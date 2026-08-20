<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

/**
 * The fluent side of {@see Model} — the same chain {@see Query} offers, but
 * every terminal method hydrates rows into model instances instead of
 * handing back arrays.
 *
 * A thin wrapper, not a second query builder: every method delegates to a
 * real {@see Query} and clones the way it does, immutably. `update()` and
 * `delete()` stay row-based rather than hydrating — a bulk write over
 * whatever this chain matched has no single instance to return.
 *
 * @template TModel of Model
 */
final class ModelQuery
{
    /**
     * @param class-string<TModel> $modelClass
     */
    private function __construct(
        private readonly string $modelClass,
        private readonly Query $query,
    ) {
    }

    /**
     * A static method does not inherit the class's `TModel` per call, so this
     * declares its own — the standard shape for a generic named constructor.
     * Named `T` rather than `TModel` because a method template shadowing the
     * class template it stands in for is itself a PHPStan-reported mistake.
     *
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return self<T>
     */
    public static function for(string $modelClass, Query $query): self
    {
        return new self($modelClass, $query);
    }

    /**
     * @return self<TModel>
     */
    public function where(string $column, string $operator, string|int|float|bool|null $value): self
    {
        return new self($this->modelClass, $this->query->where($column, $operator, $value));
    }

    /**
     * @return self<TModel>
     */
    public function whereNull(string $column): self
    {
        return new self($this->modelClass, $this->query->whereNull($column));
    }

    /**
     * @return self<TModel>
     */
    public function whereNotNull(string $column): self
    {
        return new self($this->modelClass, $this->query->whereNotNull($column));
    }

    /**
     * @param list<string|int|float|bool> $values
     * @return self<TModel>
     */
    public function whereIn(string $column, array $values): self
    {
        return new self($this->modelClass, $this->query->whereIn($column, $values));
    }

    /**
     * @return self<TModel>
     */
    public function orderBy(string $column, Direction $direction = Direction::Ascending): self
    {
        return new self($this->modelClass, $this->query->orderBy($column, $direction));
    }

    /**
     * @return self<TModel>
     */
    public function limit(int $limit): self
    {
        return new self($this->modelClass, $this->query->limit($limit));
    }

    /**
     * @return self<TModel>
     */
    public function offset(int $offset): self
    {
        return new self($this->modelClass, $this->query->offset($offset));
    }

    /**
     * Permits an `update()`/`delete()` on this chain with no `where()` at all.
     *
     * @return self<TModel>
     */
    public function affectingEveryRow(): self
    {
        return new self($this->modelClass, $this->query->affectingEveryRow());
    }

    /**
     * @return list<TModel>
     */
    public function get(): array
    {
        $class = $this->modelClass;

        return array_map(static fn (array $row) => $class::hydrate($row), $this->query->get());
    }

    /**
     * @return TModel|null
     */
    public function first(): ?Model
    {
        $row = $this->query->first();

        if ($row === null) {
            return null;
        }

        $class = $this->modelClass;

        return $class::hydrate($row);
    }

    public function count(): int
    {
        return $this->query->count();
    }

    public function exists(): bool
    {
        return $this->query->exists();
    }

    /**
     * Bulk update over the rows this chain matches. Nothing is hydrated —
     * there is no single instance to hand back for a multi-row write.
     *
     * @param array<string, scalar|null> $values
     * @return int rows changed
     */
    public function update(array $values): int
    {
        return $this->query->update($values);
    }

    /**
     * Bulk delete over the rows this chain matches.
     *
     * @return int rows removed
     */
    public function delete(): int
    {
        return $this->query->delete();
    }

    /**
     * The SELECT this chain represents. Useful in tests and when debugging.
     */
    public function toSql(): string
    {
        return $this->query->toSql();
    }
}
