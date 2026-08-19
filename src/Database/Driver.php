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
 * string literal on a default MySQL install, and `OFFSET` without `LIMIT` is a
 * syntax error on two of the three.
 */
enum Driver: string
{
    case Sqlite = 'sqlite';
    case MySql = 'mysql';
    case PostgreSql = 'pgsql';

    public static function fromName(string $name): self
    {
        $normalised = strtolower(trim($name));

        // Common spellings people actually write in a .env.
        $normalised = match ($normalised) {
            'postgres', 'postgresql' => 'pgsql',
            'mariadb' => 'mysql',
            default => $normalised,
        };

        return self::tryFrom($normalised) ?? throw new ValueError(sprintf(
            'Unknown database driver "%s". Use one of: sqlite, mysql, pgsql.',
            $name,
        ));
    }

    /**
     * The character that wraps an identifier.
     *
     * MySQL treats `"` as a string literal unless `ANSI_QUOTES` is enabled, so
     * it gets backticks. Changing the server's `sql_mode` from the client would
     * be the alternative, and a worse one: it alters how every other statement
     * on that connection is parsed, including SQL the application wrote itself.
     */
    public function delimiter(): string
    {
        return $this === self::MySql ? '`' : '"';
    }

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Sqlite => null,
            self::MySql => 3306,
            self::PostgreSql => 5432,
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
     * Spelled differently on all three, and needed by almost every migration,
     * so it is provided rather than left for each one to get wrong.
     */
    public function autoIncrementPrimaryKey(): string
    {
        return match ($this) {
            self::Sqlite => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            self::MySql => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            self::PostgreSql => 'BIGSERIAL PRIMARY KEY',
        };
    }

    /**
     * How to page past rows without capping the result.
     *
     * PostgreSQL accepts a bare OFFSET. SQLite and MySQL both require a LIMIT
     * first, and each has its own idiom for "no limit".
     */
    public function offsetWithoutLimit(int $offset): string
    {
        return match ($this) {
            self::PostgreSql => sprintf(' OFFSET %d', $offset),
            self::Sqlite => sprintf(' LIMIT -1 OFFSET %d', $offset),
            // MySQL's documented idiom: the largest possible BIGINT UNSIGNED.
            self::MySql => sprintf(' LIMIT 18446744073709551615 OFFSET %d', $offset),
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
}
