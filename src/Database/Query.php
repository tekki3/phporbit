<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

use InvalidArgumentException;

/**
 * A thin query builder over {@see Connection}.
 *
 * Deliberately not an ORM. It composes SQL with bound placeholders so that the
 * common cases read well, and it gets out of the way for anything else —
 * `Connection::select()` with hand-written SQL stays the escape hatch, and is
 * the better choice for joins, window functions and CTEs.
 *
 * Every value becomes a placeholder; every identifier goes through
 * {@see Identifier}. There is no method that accepts a raw SQL fragment,
 * because one such method makes every other guarantee here advisory.
 */
final class Query
{
    /**
     * Comparisons a caller may use.
     *
     * A whitelist rather than a pass-through: an operator is concatenated into
     * the SQL, so accepting an arbitrary string would reopen injection through
     * the one argument that looks harmless.
     *
     * @var list<string>
     */
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

    /** @var list<string> */
    private array $columns = ['*'];

    /** @var list<string> */
    private array $conditions = [];

    /** @var array<string, scalar|null> */
    private array $bindings = [];

    /** @var list<string> */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private int $placeholders = 0;

    private bool $wholeTableAcknowledged = false;

    private function __construct(
        private readonly Connection $database,
        private readonly string $table,
    ) {
        // Fail here rather than when the SQL is assembled, so a bad table name
        // points at the line that named it.
        Identifier::quote($table, $database->driver());
    }

    public static function table(Connection $database, string $table): self
    {
        return new self($database, $table);
    }

    public function select(string ...$columns): self
    {
        $clone = clone $this;
        $clone->columns = $columns === [] ? ['*'] : array_values($columns);

        return $clone;
    }

    /**
     * Adds `AND column <operator> value`.
     */
    public function where(string $column, string $operator, string|int|float|bool|null $value): self
    {
        $operator = strtoupper(trim($operator));

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Operator "%s" is not permitted. Use one of: %s.',
                $operator,
                implode(', ', self::OPERATORS),
            ));
        }

        // `= NULL` is never true in SQL; callers almost always mean IS NULL,
        // so redirect rather than silently building a query matching nothing.
        if ($value === null) {
            throw new InvalidArgumentException(
                'Comparing to null never matches. Use whereNull() or whereNotNull().',
            );
        }

        $clone = clone $this;
        $placeholder = $clone->bind($value);
        $clone->conditions[] = sprintf('%s %s :%s', $this->id($column), $operator, $placeholder);

        return $clone;
    }

    public function whereNull(string $column): self
    {
        $clone = clone $this;
        $clone->conditions[] = $this->id($column) . ' IS NULL';

        return $clone;
    }

    public function whereNotNull(string $column): self
    {
        $clone = clone $this;
        $clone->conditions[] = $this->id($column) . ' IS NOT NULL';

        return $clone;
    }

    /**
     * Adds `AND column IN (...)`.
     *
     * An empty set becomes a condition that matches nothing. `IN ()` is a
     * syntax error, and quietly dropping the condition would turn "none of
     * these" into "all rows" — the more dangerous of the two readings.
     *
     * @param list<string|int|float|bool> $values
     */
    public function whereIn(string $column, array $values): self
    {
        $clone = clone $this;

        if ($values === []) {
            $clone->conditions[] = '1 = 0';

            return $clone;
        }

        $placeholders = [];
        foreach ($values as $value) {
            $placeholders[] = ':' . $clone->bind($value);
        }

        $clone->conditions[] = sprintf(
            '%s IN (%s)',
            $this->id($column),
            implode(', ', $placeholders),
        );

        return $clone;
    }

    public function orderBy(string $column, Direction $direction = Direction::Ascending): self
    {
        $clone = clone $this;
        $clone->orders[] = $this->id($column) . ' ' . $direction->sql();

        return $clone;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('A limit cannot be negative.');
        }

        $clone = clone $this;
        $clone->limit = $limit;

        return $clone;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('An offset cannot be negative.');
        }

        $clone = clone $this;
        $clone->offset = $offset;

        return $clone;
    }

    /**
     * Permits an UPDATE or DELETE with no conditions.
     *
     * Required by {@see update()} and {@see delete()} when no `where()` was
     * added, so "change every row" is always something the caller wrote down.
     */
    public function affectingEveryRow(): self
    {
        $clone = clone $this;
        $clone->wholeTableAcknowledged = true;

        return $clone;
    }

    // --- reads ---------------------------------------------------------------

    /**
     * @return list<array<string, scalar|null>>
     */
    public function get(): array
    {
        return $this->database->select($this->toSql(), $this->bindings);
    }

    /**
     * @return array<string, scalar|null>|null
     */
    public function first(): ?array
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    public function value(string $column): string|int|float|bool|null
    {
        $row = $this->select($column)->first();

        if ($row === null) {
            return null;
        }

        return array_values($row)[0] ?? null;
    }

    public function count(): int
    {
        // Ordering and paging are meaningless for a count and would only make
        // some engines refuse the query.
        $counter = clone $this;
        $counter->orders = [];
        $counter->limit = null;
        $counter->offset = null;
        $counter->columns = ['*'];

        $sql = 'SELECT COUNT(*) AS ' . $this->id('aggregate') . ' FROM ' . $this->id($this->table)
            . $counter->whereClause();

        return (int) $this->database->selectValue($sql, $counter->bindings);
    }

    public function exists(): bool
    {
        return $this->limit(1)->select('1')->first() !== null;
    }

    // --- writes --------------------------------------------------------------

    /**
     * Inserts one row and returns its generated id.
     *
     * @param array<string, scalar|null> $values
     * @param string $key the auto-generated column, needed by engines that must
     *                    be asked for it by name rather than after the fact
     */
    public function insert(array $values, string $key = 'id'): int
    {
        if ($values === []) {
            throw new InvalidArgumentException('insert() needs at least one column.');
        }

        $columns = [];
        $placeholders = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            $columns[] = $this->id($column);
            $name = 'i' . count($bindings);
            $placeholders[] = ':' . $name;
            $bindings[$name] = $value;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->id($this->table),
            implode(', ', $columns),
            implode(', ', $placeholders),
        );

        // PostgreSQL's lastInsertId() calls lastval(), which returns the last
        // value from *any* sequence touched in the session — fine in isolation,
        // wrong as soon as a trigger writes elsewhere. RETURNING asks the
        // question that is actually being asked.
        if ($this->database->driver()->usesReturningForInsertId()) {
            $row = $this->database->selectOne(
                $sql . ' RETURNING ' . $this->id($key),
                $bindings,
            );

            return (int) ($row[$key] ?? 0);
        }

        $this->database->execute($sql, $bindings);

        return (int) $this->database->lastInsertId();
    }

    /**
     * @param array<string, scalar|null> $values
     * @return int rows changed
     */
    public function update(array $values): int
    {
        if ($values === []) {
            throw new InvalidArgumentException('update() needs at least one column.');
        }

        $this->assertScoped('UPDATE');

        $assignments = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            $name = 'u' . count($bindings);
            $assignments[] = sprintf('%s = :%s', $this->id($column), $name);
            $bindings[$name] = $value;
        }

        return $this->database->execute(
            sprintf(
                'UPDATE %s SET %s%s',
                $this->id($this->table),
                implode(', ', $assignments),
                $this->whereClause(),
            ),
            [...$bindings, ...$this->bindings],
        );
    }

    /**
     * @return int rows removed
     */
    public function delete(): int
    {
        $this->assertScoped('DELETE');

        return $this->database->execute(
            'DELETE FROM ' . $this->id($this->table) . $this->whereClause(),
            $this->bindings,
        );
    }

    // --- inspection ----------------------------------------------------------

    /**
     * The SELECT this builder represents. Useful in tests and when debugging.
     */
    public function toSql(): string
    {
        $columns = implode(', ', array_map(
            // A bare "1" is how exists() asks for the cheapest possible row.
            fn (string $column): string => $column === '1' ? '1' : $this->id($column),
            $this->columns,
        ));

        $sql = 'SELECT ' . $columns . ' FROM ' . $this->id($this->table) . $this->whereClause();

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            // Paging without a cap is spelled differently on each engine, and
            // two of the three reject a bare OFFSET outright.
            $sql .= $this->limit === null
                ? $this->database->driver()->offsetWithoutLimit($this->offset)
                : ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /**
     * Quotes an identifier for the engine this query will run on.
     */
    private function id(string $name): string
    {
        return Identifier::quote($name, $this->database->driver());
    }

    /**
     * @return array<string, scalar|null>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    private function whereClause(): string
    {
        return $this->conditions === [] ? '' : ' WHERE ' . implode(' AND ', $this->conditions);
    }

    private function assertScoped(string $statement): void
    {
        if ($this->conditions === [] && !$this->wholeTableAcknowledged) {
            throw new UnsafeQuery(sprintf(
                'This %s has no conditions and would affect every row of "%s". Add a where(), '
                . 'or call affectingEveryRow() if that is genuinely the intent.',
                $statement,
                $this->table,
            ));
        }
    }

    private function bind(string|int|float|bool $value): string
    {
        $name = 'p' . $this->placeholders++;
        $this->bindings[$name] = $value;

        return $name;
    }
}
