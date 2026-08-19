<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * A database connection that only speaks in prepared statements.
 *
 * There is no method that accepts a fully-built query string with values in
 * it. Every value goes through {@see PDOStatement::execute()}, so a value can
 * never be parsed as SQL. Identifiers — table and column names — cannot be
 * bound by any driver, so anything dynamic there must come from a list the
 * application controls, never from a request.
 *
 * Emulated prepares are switched off. With emulation on, PDO interpolates the
 * values itself before sending the query, which reintroduces exactly the class
 * of bug prepared statements exist to remove.
 *
 * Under a worker this object is a singleton: reconnecting per request would be
 * wasteful. That makes leftover transaction state a cross-request hazard, which
 * {@see TransactionGuard} closes off.
 */
final class Connection
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Driver $driver = Driver::Sqlite,
    ) {
    }

    /**
     * Which engine this connection speaks to.
     *
     * Needed by the query builder for the handful of places the engines differ,
     * and available to migrations that genuinely need engine-specific DDL.
     */
    public function driver(): Driver
    {
        return $this->driver;
    }

    /**
     * Opens a connection from validated settings.
     *
     * The three PDO attributes are not optional extras. Exceptions make a
     * failure impossible to ignore, associative fetches are what
     * {@see narrowRow()} expects, and **emulated prepares must be off**: with
     * emulation on, PDO interpolates values itself before sending the query,
     * which reintroduces exactly the class of bug prepared statements exist to
     * remove.
     */
    public static function connect(DatabaseSettings $settings): self
    {
        try {
            $pdo = new PDO(
                $settings->dsn(),
                $settings->username,
                $settings->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
        } catch (PDOException $e) {
            // The DSN names the host and database but carries no credentials,
            // so it is safe to show and it is what makes the failure fixable.
            throw new ConnectionFailed(sprintf(
                'Could not connect to %s: %s',
                $settings->dsn(),
                $e->getMessage(),
            ), 0, $e);
        }

        $connection = new self($pdo, $settings->driver);
        $connection->applySessionDefaults($settings);

        return $connection;
    }

    public static function sqlite(string $path): self
    {
        return self::connect(DatabaseSettings::sqlite($path));
    }

    /**
     * Per-connection settings that make the three engines behave alike.
     */
    private function applySessionDefaults(DatabaseSettings $settings): void
    {
        match ($this->driver) {
            // SQLite leaves referential integrity off unless asked, per connection.
            Driver::Sqlite => $this->pdo->exec('PRAGMA foreign_keys = ON'),

            // Without this, MySQL silently truncates over-long values and turns
            // a division by zero into NULL — data loss reported as success.
            Driver::MySql => $this->pdo->exec(
                "SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            ),

            // PostgreSQL is strict already; only the client encoding is worth
            // pinning so it does not depend on the server's default.
            Driver::PostgreSql => $this->pdo->exec(
                sprintf("SET client_encoding TO '%s'", $settings->charset === 'utf8' ? 'UTF8' : $settings->charset),
            ),
        };
    }

    /**
     * Starts a query builder against a table.
     *
     * A convenience over {@see Query::table()}; hand-written SQL through
     * {@see select()} remains the right choice for anything the builder does
     * not cover.
     */
    public function query(string $table): Query
    {
        return Query::table($this, $table);
    }

    /**
     * @param array<string, scalar|null> $parameters
     * @return list<array<string, scalar|null>>
     */
    public function select(string $sql, array $parameters = []): array
    {
        $statement = $this->run($sql, $parameters);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $rows[] = $this->narrowRow($row);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, scalar|null> $parameters
     * @return array<string, scalar|null>|null
     */
    public function selectOne(string $sql, array $parameters = []): ?array
    {
        return $this->select($sql, $parameters)[0] ?? null;
    }

    /**
     * A single scalar, for counts and existence checks.
     *
     * @param array<string, scalar|null> $parameters
     */
    public function selectValue(string $sql, array $parameters = []): string|int|float|bool|null
    {
        $row = $this->selectOne($sql, $parameters);

        if ($row === null) {
            return null;
        }

        return array_values($row)[0] ?? null;
    }

    /**
     * Runs a write and returns the number of affected rows.
     *
     * @param array<string, scalar|null> $parameters
     */
    public function execute(string $sql, array $parameters = []): int
    {
        return $this->run($sql, $parameters)->rowCount();
    }

    /**
     * Runs schema statements, which take no parameters by definition.
     *
     * Separate from {@see execute()} so that reaching for a method with no
     * parameter binding is a visible decision rather than a convenient
     * shortcut for interpolating a value.
     */
    public function executeSchema(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            throw QueryFailed::from($e, $sql);
        }
    }

    /**
     * The id generated by the last insert.
     *
     * Drivers that cannot report one return false; that is a configuration
     * problem rather than something a caller can handle, so it throws instead
     * of returning a value that would silently become 0.
     */
    public function lastInsertId(): string
    {
        $id = $this->pdo->lastInsertId();

        if ($id === false) {
            throw new RuntimeException('This driver does not report last insert ids.');
        }

        return $id;
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Runs work inside a transaction, rolling back if it throws.
     *
     * @template T
     * @param Closure(self): T $work
     * @return T
     */
    public function transaction(Closure $work): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $work($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Abandons an open transaction. Used for cleanup, not control flow.
     */
    public function rollBackIfOpen(): bool
    {
        if (!$this->pdo->inTransaction()) {
            return false;
        }

        $this->pdo->rollBack();

        return true;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function run(string $sql, array $parameters): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters === [] ? null : $parameters);

            return $statement;
        } catch (PDOException $e) {
            throw QueryFailed::from($e, $sql);
        }
    }

    /**
     * The driver boundary: results arrive untyped and are narrowed once here.
     *
     * @param array<array-key, mixed> $row
     * @return array<string, scalar|null>
     */
    private function narrowRow(array $row): array
    {
        $narrowed = [];

        foreach ($row as $column => $value) {
            if (!is_string($column)) {
                continue;
            }

            $narrowed[$column] = is_scalar($value) ? $value : null;
        }

        return $narrowed;
    }
}
