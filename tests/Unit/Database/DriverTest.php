<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Database;

use PhpOrbit\Config\Environment;
use PhpOrbit\Database\DatabaseSettings;
use PhpOrbit\Database\Driver;
use PhpOrbit\Database\Identifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * The engine differences the framework claims to handle.
 *
 * Only pdo_sqlite is installed on most development machines, so these tests
 * cover the SQL and DSN the framework *generates* for each engine rather than
 * round-tripping against a live server. That is the part that can silently
 * regress; connecting is verified by whoever runs it against a real database.
 */
final class DriverTest extends TestCase
{
    #[DataProvider('names')]
    public function test_it_accepts_the_spellings_people_actually_write(string $name, Driver $expected): void
    {
        self::assertSame($expected, Driver::fromName($name));
    }

    /**
     * @return iterable<string, array{string, Driver}>
     */
    public static function names(): iterable
    {
        yield 'sqlite' => ['sqlite', Driver::Sqlite];
        yield 'mysql' => ['mysql', Driver::MySql];
        yield 'mariadb' => ['mariadb', Driver::MySql];
        yield 'pgsql' => ['pgsql', Driver::PostgreSql];
        yield 'postgres' => ['postgres', Driver::PostgreSql];
        yield 'postgresql' => ['postgresql', Driver::PostgreSql];
        yield 'mixed case and spaces' => ['  PostgreSQL ', Driver::PostgreSql];
    }

    public function test_an_unknown_driver_names_the_supported_ones(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessageMatches('/sqlite, mysql, pgsql/');

        Driver::fromName('oracle');
    }

    /**
     * MySQL reads a double-quoted identifier as a string literal unless
     * ANSI_QUOTES is on, so it gets backticks instead.
     */
    public function test_identifiers_use_each_engines_delimiter(): void
    {
        self::assertSame('"title"', Identifier::quote('title', Driver::Sqlite));
        self::assertSame('"title"', Identifier::quote('title', Driver::PostgreSql));
        self::assertSame('`title`', Identifier::quote('title', Driver::MySql));

        self::assertSame('`notes`.`title`', Identifier::quote('notes.title', Driver::MySql));
    }

    /**
     * The validation is what makes quoting safe, and it must not vary by
     * engine — otherwise one driver becomes the weak one.
     */
    #[DataProvider('drivers')]
    public function test_hostile_identifiers_are_refused_on_every_engine(Driver $driver): void
    {
        foreach (['title; DROP TABLE users', 'a"b', 'a`b', '1abc', ''] as $hostile) {
            self::assertFalse(
                Identifier::isValid($hostile),
                sprintf('%s accepted "%s"', $driver->value, $hostile),
            );
        }
    }

    /**
     * @return iterable<string, array{Driver}>
     */
    public static function drivers(): iterable
    {
        yield 'sqlite' => [Driver::Sqlite];
        yield 'mysql' => [Driver::MySql];
        yield 'pgsql' => [Driver::PostgreSql];
    }

    /**
     * Two of the three reject a bare OFFSET, and each spells "no limit"
     * differently.
     */
    public function test_offset_without_limit_uses_each_engines_idiom(): void
    {
        self::assertSame(' OFFSET 40', Driver::PostgreSql->offsetWithoutLimit(40));
        self::assertSame(' LIMIT -1 OFFSET 40', Driver::Sqlite->offsetWithoutLimit(40));
        self::assertSame(' LIMIT 18446744073709551615 OFFSET 40', Driver::MySql->offsetWithoutLimit(40));
    }

    public function test_each_engine_spells_an_auto_increment_key_its_own_way(): void
    {
        self::assertStringContainsString('AUTOINCREMENT', Driver::Sqlite->autoIncrementPrimaryKey());
        self::assertStringContainsString('AUTO_INCREMENT', Driver::MySql->autoIncrementPrimaryKey());
        self::assertStringContainsString('BIGSERIAL', Driver::PostgreSql->autoIncrementPrimaryKey());
    }

    /**
     * MySQL commits implicitly on DDL, so the migrator's per-migration
     * transaction cannot roll a failed schema change back there.
     */
    public function test_only_mysql_lacks_transactional_ddl(): void
    {
        self::assertTrue(Driver::Sqlite->supportsTransactionalDdl());
        self::assertTrue(Driver::PostgreSql->supportsTransactionalDdl());
        self::assertFalse(Driver::MySql->supportsTransactionalDdl());
    }

    public function test_only_postgres_needs_returning_for_the_insert_id(): void
    {
        self::assertTrue(Driver::PostgreSql->usesReturningForInsertId());
        self::assertFalse(Driver::MySql->usesReturningForInsertId());
        self::assertFalse(Driver::Sqlite->usesReturningForInsertId());
    }

    // --- settings -------------------------------------------------------------

    public function test_it_builds_a_dsn_for_each_engine(): void
    {
        self::assertSame(
            'sqlite:/srv/app/storage/app.sqlite',
            DatabaseSettings::sqlite('/srv/app/storage/app.sqlite')->dsn(),
        );

        self::assertSame(
            'mysql:host=db.internal;port=3307;dbname=orbit;charset=utf8mb4',
            DatabaseSettings::mysql('orbit', 'db.internal', 3307)->dsn(),
        );

        self::assertSame(
            'pgsql:host=db.internal;port=5433;dbname=orbit',
            DatabaseSettings::postgres('orbit', 'db.internal', 5433)->dsn(),
        );
    }

    public function test_a_mysql_socket_replaces_the_host(): void
    {
        $settings = DatabaseSettings::fromEnvironment(
            Environment::fromArray([
                'DB_DRIVER' => 'mysql',
                'DB_DATABASE' => 'orbit',
                'DB_SOCKET' => '/var/run/mysqld/mysqld.sock',
            ]),
            '/srv/app',
        );

        self::assertStringContainsString('unix_socket=/var/run/mysqld/mysqld.sock', $settings->dsn());
        self::assertStringNotContainsString('host=', $settings->dsn());
    }

    public function test_sqlite_paths_resolve_against_the_project_root(): void
    {
        $settings = DatabaseSettings::fromEnvironment(
            Environment::fromArray(['DB_DRIVER' => 'sqlite', 'DB_DATABASE' => 'storage/app.sqlite']),
            '/srv/app',
        );

        self::assertSame('sqlite:/srv/app/storage/app.sqlite', $settings->dsn());
    }

    /**
     * ":memory:" is a driver keyword, not a path, and must survive untouched.
     */
    public function test_an_in_memory_sqlite_database_is_not_treated_as_a_path(): void
    {
        $settings = DatabaseSettings::fromEnvironment(
            Environment::fromArray(['DB_DRIVER' => 'sqlite', 'DB_DATABASE' => ':memory:']),
            '/srv/app',
        );

        self::assertSame('sqlite::memory:', $settings->dsn());
    }

    public function test_a_server_engine_requires_a_database_name(): void
    {
        $this->expectExceptionMessageMatches('/DB_DATABASE/');

        DatabaseSettings::fromEnvironment(
            Environment::fromArray(['DB_DRIVER' => 'pgsql']),
            '/srv/app',
        );
    }

    public function test_ports_are_range_checked(): void
    {
        $this->expectExceptionMessageMatches('/DB_PORT must be between/');

        DatabaseSettings::fromEnvironment(
            Environment::fromArray([
                'DB_DRIVER' => 'mysql',
                'DB_DATABASE' => 'orbit',
                'DB_PORT' => '70000',
            ]),
            '/srv/app',
        );
    }

    public function test_each_engine_gets_its_default_port(): void
    {
        foreach ([['mysql', 3306], ['pgsql', 5432]] as [$name, $port]) {
            $settings = DatabaseSettings::fromEnvironment(
                Environment::fromArray(['DB_DRIVER' => $name, 'DB_DATABASE' => 'orbit']),
                '/srv/app',
            );

            self::assertStringContainsString('port=' . $port, $settings->dsn(), $name);
        }
    }

    // --- what the builder emits per engine ------------------------------------

    /**
     * The builder is given a connection labelled with each engine and asked
     * what SQL it would produce. Nothing is executed, so this runs anywhere —
     * which is the point: the generated SQL is what regresses silently.
     */
    #[DataProvider('drivers')]
    public function test_the_builder_quotes_for_the_connections_engine(Driver $driver): void
    {
        $sql = $this->queryOn($driver)
            ->select('id', 'title')
            ->where('title', '!=', 'x')
            ->orderBy('id')
            ->toSql();

        $delimiter = $driver->delimiter();

        self::assertStringContainsString($delimiter . 'title' . $delimiter, $sql);
        self::assertStringContainsString($delimiter . 'notes' . $delimiter, $sql);

        // The value is still bound, on every engine.
        self::assertStringContainsString(':p0', $sql);
        self::assertStringNotContainsString("'x'", $sql);
    }

    public function test_the_builder_pages_using_each_engines_offset_idiom(): void
    {
        self::assertStringEndsWith(
            ' OFFSET 40',
            $this->queryOn(Driver::PostgreSql)->offset(40)->toSql(),
        );
        self::assertStringEndsWith(
            ' LIMIT -1 OFFSET 40',
            $this->queryOn(Driver::Sqlite)->offset(40)->toSql(),
        );
        self::assertStringEndsWith(
            ' LIMIT 18446744073709551615 OFFSET 40',
            $this->queryOn(Driver::MySql)->offset(40)->toSql(),
        );

        // With an explicit limit, all three agree.
        self::assertStringEndsWith(
            ' LIMIT 10 OFFSET 40',
            $this->queryOn(Driver::MySql)->limit(10)->offset(40)->toSql(),
        );
    }

    private function queryOn(Driver $driver): \PhpOrbit\Database\Query
    {
        // A real SQLite handle, labelled as another engine. Only SQL generation
        // is under test here; nothing is sent to the database.
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        return (new \PhpOrbit\Database\Connection($pdo, $driver))->query('notes');
    }

    /**
     * Settings travel into logs and stack traces the moment something fails.
     */
    public function test_the_password_is_redacted_from_debug_output(): void
    {
        $settings = DatabaseSettings::mysql('orbit', password: 'hunter2');

        $dumped = print_r($settings, true);

        self::assertStringNotContainsString('hunter2', $dumped);
        self::assertStringContainsString('redacted', $dumped);
    }
}
