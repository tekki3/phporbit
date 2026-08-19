<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Integration;

use PhpOrbit\Database\Connection;
use PhpOrbit\Database\DatabaseSettings;
use PhpOrbit\Database\Direction;
use PhpOrbit\Database\Driver;
use PhpOrbit\Database\Migrator;
use PhpOrbit\Database\UnsafeQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The database layer against a real server.
 *
 * Everything here is a claim the unit tests could not check. They assert the
 * SQL phporbit *generates*; these assert a server *accepts* it — which is a
 * different question, and the one that matters. Every engine difference the
 * framework encodes gets exercised: backtick quoting on MySQL, `RETURNING` on
 * PostgreSQL, the three spellings of an auto-increment key, and paging without
 * a limit, which is a syntax error on two of the three.
 */
final class DatabaseTest extends TestCase
{
    use RequiresService;

    private ?Connection $database = null;

    private string $table = '';

    protected function tearDown(): void
    {
        if ($this->database !== null && $this->table !== '') {
            try {
                $this->database->executeSchema('DROP TABLE IF EXISTS ' . $this->table);
            } catch (RuntimeException) {
                // The test may have failed before the table existed.
            }
        }
    }

    /**
     * @return iterable<string, array{Driver}>
     */
    public static function servers(): iterable
    {
        yield 'mysql' => [Driver::MySql];
        yield 'pgsql' => [Driver::PostgreSql];
    }

    #[DataProvider('servers')]
    public function test_it_creates_reads_updates_and_deletes(Driver $driver): void
    {
        $database = $this->connect($driver);

        $id = $database->query($this->table)->insert([
            'title' => 'first',
            'body' => 'hello',
            'created_at' => gmdate('c'),
        ]);

        // PostgreSQL cannot answer this with lastInsertId(), so the builder
        // uses RETURNING there. A wrong id here means that path is broken.
        self::assertGreaterThan(0, $id);

        $row = $database->query($this->table)->where('id', '=', $id)->first();

        self::assertNotNull($row);
        self::assertSame('first', $row['title']);

        self::assertSame(1, $database->query($this->table)->where('id', '=', $id)->update(['title' => 'edited']));
        self::assertSame('edited', $database->query($this->table)->where('id', '=', $id)->value('title'));

        self::assertSame(1, $database->query($this->table)->where('id', '=', $id)->delete());
        self::assertSame(0, $database->query($this->table)->count());
    }

    /**
     * Identifier quoting is the difference most likely to break: a double quote
     * is a string literal on MySQL, so a mis-quoted column silently compares a
     * constant instead of a field.
     */
    #[DataProvider('servers')]
    public function test_identifiers_are_quoted_the_way_this_server_expects(Driver $driver): void
    {
        $database = $this->connect($driver);

        $database->query($this->table)->insert(['title' => 'quoted', 'body' => 'x', 'created_at' => gmdate('c')]);

        // If the quoting were wrong, this would compare the literal string
        // "title" with "quoted" and match nothing.
        self::assertSame(1, $database->query($this->table)->where('title', '=', 'quoted')->count());
        self::assertSame(0, $database->query($this->table)->where('title', '=', 'absent')->count());
    }

    /**
     * A bare OFFSET is a syntax error on SQLite and MySQL; each engine gets its
     * own idiom, and only a server can confirm the idiom is right.
     */
    #[DataProvider('servers')]
    public function test_paging_without_a_limit_is_accepted(Driver $driver): void
    {
        $database = $this->connect($driver);

        foreach (['a', 'b', 'c'] as $title) {
            $database->query($this->table)->insert(['title' => $title, 'body' => 'x', 'created_at' => gmdate('c')]);
        }

        $rows = $database->query($this->table)
            ->orderBy('title', Direction::Ascending)
            ->offset(1)
            ->get();

        self::assertCount(2, $rows);
        self::assertSame('b', $rows[0]['title']);
    }

    #[DataProvider('servers')]
    public function test_values_are_bound_rather_than_interpolated(Driver $driver): void
    {
        $database = $this->connect($driver);

        $payload = "'; DROP TABLE " . $this->table . '; --';

        $database->query($this->table)->insert(['title' => $payload, 'body' => 'x', 'created_at' => gmdate('c')]);

        // The table still exists, and the payload came back as data.
        self::assertSame(1, $database->query($this->table)->where('title', '=', $payload)->count());
    }

    #[DataProvider('servers')]
    public function test_a_transaction_rolls_back(Driver $driver): void
    {
        $database = $this->connect($driver);

        try {
            $database->transaction(function (Connection $connection): void {
                $connection->query($this->table)->insert(['title' => 'doomed', 'body' => 'x', 'created_at' => gmdate('c')]);

                throw new RuntimeException('deliberate');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        self::assertSame(0, $database->query($this->table)->count());
        self::assertFalse($database->inTransaction(), 'the connection should not be left in a transaction');
    }

    #[DataProvider('servers')]
    public function test_an_unqualified_delete_is_still_refused(Driver $driver): void
    {
        $database = $this->connect($driver);

        $database->query($this->table)->insert(['title' => 'keep', 'body' => 'x', 'created_at' => gmdate('c')]);

        try {
            $database->query($this->table)->delete();
            self::fail('an unqualified DELETE should have been refused');
        } catch (UnsafeQuery) {
            // The guard is application-side, but the row surviving is what
            // proves nothing reached the server.
        }

        self::assertSame(1, $database->query($this->table)->count());
    }

    /**
     * The migrator against a real server: the auto-increment spelling and the
     * ledger table are both engine-specific in ways SQLite cannot reveal.
     */
    #[DataProvider('servers')]
    public function test_migrations_apply_and_reverse(Driver $driver): void
    {
        $database = $this->connect($driver, migrate: false);

        $directory = sys_get_temp_dir() . '/orbit-migrations-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o755, true);

        $table = $this->table;

        file_put_contents($directory . '/0001_create_integration_table.php', <<<PHP
            <?php
            use PhpOrbit\\Database\\Connection;
            use PhpOrbit\\Database\\Migration;

            return new class implements Migration {
                public function up(Connection \$database): void
                {
                    \$database->executeSchema(sprintf(
                        'CREATE TABLE {$table} (id %s, title VARCHAR(191) NOT NULL)',
                        \$database->driver()->autoIncrementPrimaryKey(),
                    ));
                }

                public function down(Connection \$database): void
                {
                    \$database->executeSchema('DROP TABLE {$table}');
                }
            };
            PHP);

        try {
            $migrator = new Migrator($database, $directory);

            self::assertCount(1, $migrator->migrate());
            self::assertSame([], $migrator->pending());

            // The generated key really does auto-assign on this engine.
            $id = $database->query($this->table)->insert(['title' => 'from a migration']);
            self::assertGreaterThan(0, $id);

            self::assertCount(1, $migrator->rollback());
        } finally {
            foreach (glob($directory . '/*.php') ?: [] as $file) {
                unlink($file);
            }

            @rmdir($directory);

            try {
                $database->executeSchema('DROP TABLE IF EXISTS ' . Migrator::LEDGER_TABLE);
            } catch (RuntimeException) {
                // Already gone.
            }
        }
    }

    /**
     * Opens a connection, skipping when the server is not configured.
     */
    private function connect(Driver $driver, bool $migrate = true): Connection
    {
        $prefix = $driver === Driver::MySql ? 'MYSQL' : 'PGSQL';

        $env = $this->requireEnvironment(
            [$prefix . '_HOST', $prefix . '_PORT', $prefix . '_DATABASE', $prefix . '_USERNAME'],
            $driver->value,
        );

        if (!in_array($driver->value, \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped(sprintf('pdo_%s is not installed.', $driver->value));
        }

        $this->requireReachable($env[$prefix . '_HOST'], (int) $env[$prefix . '_PORT'], $driver->value);

        $settings = $driver === Driver::MySql
            ? DatabaseSettings::mysql(
                $env['MYSQL_DATABASE'],
                $env['MYSQL_HOST'],
                (int) $env['MYSQL_PORT'],
                $env['MYSQL_USERNAME'],
                getenv('MYSQL_PASSWORD') === false ? null : (string) getenv('MYSQL_PASSWORD'),
            )
            : DatabaseSettings::postgres(
                $env['PGSQL_DATABASE'],
                $env['PGSQL_HOST'],
                (int) $env['PGSQL_PORT'],
                $env['PGSQL_USERNAME'],
                getenv('PGSQL_PASSWORD') === false ? null : (string) getenv('PGSQL_PASSWORD'),
            );

        $this->database = Connection::connect($settings);

        // A table per test, so a failure cannot strand the next one.
        $this->table = 'orbit_it_' . bin2hex(random_bytes(4));

        if ($migrate) {
            $this->database->executeSchema(sprintf(
                'CREATE TABLE %s (id %s, title VARCHAR(191) NOT NULL, body TEXT NOT NULL, created_at VARCHAR(40) NOT NULL)',
                $this->table,
                $this->database->driver()->autoIncrementPrimaryKey(),
            ));
        }

        return $this->database;
    }
}
