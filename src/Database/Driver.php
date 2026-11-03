<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

use ValueError;

/**
 * The database engines phporbit speaks to, and the places they differ.
 *
 * Every difference the framework cares about is answered here rather than
 * scattered through the query builder as `if ($driver === ...)`. Adding an
 * engine means implementing these methods and nothing else.
 *
 * The differences are real, not cosmetic: an identifier quoted with `"` is a
 * string literal on a default MySQL install, SQL Server uses OFFSET/FETCH, and
 * each engine spells an auto-generated key differently.
 */
enum Driver: string
{
    case Sqlite = 'sqlite';
    case MySql = 'mysql';
    case PostgreSql = 'pgsql';
    case SqlServer = 'sqlsrv';

    public static function fromName(string $name): self
    {
        $normalised = strtolower(trim($name));

        // Common spellings people actually write in a .env.
        $normalised = match ($normalised) {
            'postgres', 'postgresql' => 'pgsql',
            'mariadb' => 'mysql',
            'sqlserver', 'sql_server', 'mssql' => 'sqlsrv',
            default => $normalised,
        };

        return self::tryFrom($normalised) ?? throw new ValueError(sprintf(
            'Unknown database driver "%s". Use one of: sqlite, mysql, pgsql, sqlsrv.',
            $name,
        ));
    }

    /**
     * The opening delimiter for an identifier.
     *
     * MySQL treats `"` as a string literal unless `ANSI_QUOTES` is enabled, so
     * it gets backticks. SQL Server uses brackets so quoting does not depend on
     * its QUOTED_IDENTIFIER setting.
     */
    public function delimiter(): string
    {
        return match ($this) {
            self::MySql => '`',
            self::SqlServer => '[',
            default => '"',
        };
    }

    public function closingDelimiter(): string
    {
        return $this === self::SqlServer ? ']' : $this->delimiter();
    }

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Sqlite => null,
            self::MySql => 3306,
            self::PostgreSql => 5432,
            self::SqlServer => 1433,
        };
    }

    public function needsServer(): bool
    {
        return $this !== self::Sqlite;
    }

    /**
     * Whether a failed migration is rolled back cleanly.
     *
     * SQLite and PostgreSQL support transactional DDL, so a migration that
     * throws leaves the schema untouched. MySQL commits implicitly on most DDL,
     * so a failure there can leave a half-applied change behind. The migrator
     * still opens a transaction — it is not harmful — but this is what decides
     * whether the guarantee can be advertised.
     */
    public function supportsTransactionalDdl(): bool
    {
        return $this !== self::MySql;
    }

    /**
     * A primary key column that assigns its own value.
     *
     * Spelled differently by each engine, and needed by almost every migration,
     * so it is provided rather than left for each one to get wrong.
     */
    public function autoIncrementPrimaryKey(): string
    {
        return match ($this) {
            self::Sqlite => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            self::MySql => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            self::PostgreSql => 'BIGSERIAL PRIMARY KEY',
            self::SqlServer => 'BIGINT IDENTITY(1,1) PRIMARY KEY',
        };
    }

    /**
     * A large text column type that supports normal comparisons on this engine.
     */
    public function textType(): string
    {
        return $this === self::SqlServer ? 'NVARCHAR(MAX)' : 'TEXT';
    }

    /**
     * Builds the idempotent table creation statement used for the migration ledger.
     */
    public function createTableIfNotExists(string $table, string $definition): string
    {
        $quoted = Identifier::quote($table, $this);

        if ($this === self::SqlServer) {
            return sprintf(
                "IF OBJECT_ID(N'%s', N'U') IS NULL CREATE TABLE %s (%s)",
                $table,
                $quoted,
                $definition,
            );
        }

        return sprintf('CREATE TABLE IF NOT EXISTS %s (%s)', $quoted, $definition);
    }

    /**
     * How to page past rows without capping the result.
     *
     * PostgreSQL accepts a bare OFFSET. SQLite and MySQL require a LIMIT
     * first, while SQL Server requires an ORDER BY and uses OFFSET/FETCH.
     */
    public function offsetWithoutLimit(int $offset): string
    {
        return match ($this) {
            self::PostgreSql => sprintf(' OFFSET %d', $offset),
            self::Sqlite => sprintf(' LIMIT -1 OFFSET %d', $offset),
            // MySQL's documented idiom: the largest possible BIGINT UNSIGNED.
            self::MySql => sprintf(' LIMIT 18446744073709551615 OFFSET %d', $offset),
            self::SqlServer => sprintf(' OFFSET %d ROWS', $offset),
        };
    }

    /**
     * Whether an INSERT should ask for the generated key back directly.
     *
     * PDO's lastInsertId() on PostgreSQL calls `lastval()`, which is the last
     * value from *any* sequence touched in the session — fine in isolation and
     * wrong the moment a trigger writes to another table. `RETURNING` asks the
     * one question that is actually being asked.
     */
    public function usesReturningForInsertId(): bool
    {
        return $this === self::PostgreSql;
    }

    /**
     * Whether an INSERT returns its generated key through an OUTPUT clause.
     */
    public function usesOutputForInsertId(): bool
    {
        return $this === self::SqlServer;
    }

    /**
     * Whether paging uses SQL Server's OFFSET/FETCH syntax.
     */
    public function usesOffsetFetch(): bool
    {
        return $this === self::SqlServer;
    }

    /**
     * Whether PDO's emulated-prepare option is supported.
     *
     * PDO_SQLSRV always uses native prepared statements and does not expose
     * PDO::ATTR_EMULATE_PREPARES.
     */
    public function supportsEmulatedPreparesOption(): bool
    {
        return $this !== self::SqlServer;
    }

    public function defaultCharset(): string
    {
        return match ($this) {
            self::MySql => 'utf8mb4',
            self::PostgreSql => 'utf8',
            self::SqlServer => 'UTF-8',
            self::Sqlite => 'utf8',
        };
    }
}
