<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Database;

use PhpOrbit\Database\Connection;
use PhpOrbit\Database\IrreversibleMigration;
use PhpOrbit\Database\MigrationFailed;
use PhpOrbit\Database\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private Connection $db;

    private string $directory;

    protected function setUp(): void
    {
        $this->db = Connection::sqlite(':memory:');
        $this->directory = sys_get_temp_dir() . '/orbit-migrations-' . bin2hex(random_bytes(6));

        mkdir($this->directory, 0o750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function test_it_applies_pending_migrations_in_filename_order(): void
    {
        $this->write('0002_create_posts', 'posts');
        $this->write('0001_create_users', 'users');

        $applied = $this->migrator()->migrate();

        self::assertSame(['0001_create_users', '0002_create_posts'], $applied);
        self::assertTrue($this->tableExists('users'));
        self::assertTrue($this->tableExists('posts'));
    }

    public function test_running_twice_applies_nothing_the_second_time(): void
    {
        $this->write('0001_create_users', 'users');

        $migrator = $this->migrator();
        $migrator->migrate();

        self::assertSame([], $migrator->migrate());
        self::assertSame(['0001_create_users'], $migrator->applied());
        self::assertSame([], $migrator->pending());
    }

    public function test_pending_lists_only_unapplied_migrations(): void
    {
        $this->write('0001_create_users', 'users');
        $migrator = $this->migrator();
        $migrator->migrate();

        $this->write('0002_create_posts', 'posts');

        self::assertSame(['0002_create_posts'], $migrator->pending());
    }

    /**
     * A failure must leave neither schema changes nor a ledger entry, so the
     * migration can be fixed and re-run.
     */
    public function test_a_failing_migration_rolls_back_and_is_not_recorded(): void
    {
        $this->write('0001_create_users', 'users');
        file_put_contents(
            $this->directory . '/0002_broken.php',
            <<<'PHP'
                <?php
                use PhpOrbit\Database\Connection;
                use PhpOrbit\Database\Migration;
                return new class implements Migration {
                    public function up(Connection $database): void {
                        $database->executeSchema('CREATE TABLE fine (id INTEGER PRIMARY KEY)');
                        $database->executeSchema('THIS IS NOT SQL');
                    }
                    public function down(Connection $database): void {}
                };
                PHP,
        );

        $migrator = $this->migrator();

        try {
            $migrator->migrate();

            self::fail('the broken migration should have failed');
        } catch (MigrationFailed $e) {
            self::assertStringContainsString('0002_broken', $e->getMessage());
        }

        self::assertSame(['0001_create_users'], $migrator->applied());
        self::assertFalse($this->tableExists('fine'), 'the partial change must be rolled back');
    }

    public function test_rollback_reverses_the_last_batch(): void
    {
        $this->write('0001_create_users', 'users');
        $migrator = $this->migrator();
        $migrator->migrate();

        $this->write('0002_create_posts', 'posts');
        $migrator->migrate();

        $reversed = $migrator->rollback();

        self::assertSame(['0002_create_posts'], $reversed);
        self::assertFalse($this->tableExists('posts'));
        self::assertTrue($this->tableExists('users'), 'the earlier batch is untouched');
    }

    public function test_migrations_applied_together_share_a_batch(): void
    {
        $this->write('0001_create_users', 'users');
        $this->write('0002_create_posts', 'posts');

        $migrator = $this->migrator();
        $migrator->migrate();

        self::assertSame([1, 1], array_values($migrator->batches()));

        $migrator->rollback();

        self::assertSame([], $migrator->applied(), 'one batch, so both are reversed together');
    }

    /**
     * The batch is checked for reversibility before anything is undone.
     */
    public function test_an_irreversible_migration_stops_the_rollback(): void
    {
        file_put_contents(
            $this->directory . '/0001_irreversible.php',
            <<<'PHP'
                <?php
                use PhpOrbit\Database\Connection;
                use PhpOrbit\Database\IrreversibleMigration;
                use PhpOrbit\Database\Migration;
                return new class implements Migration {
                    public function up(Connection $database): void {
                        $database->executeSchema('CREATE TABLE users (id INTEGER PRIMARY KEY)');
                    }
                    public function down(Connection $database): void {
                        throw IrreversibleMigration::because('the dropped column cannot be reconstructed');
                    }
                };
                PHP,
        );

        $migrator = $this->migrator();
        $migrator->migrate();

        $this->expectException(IrreversibleMigration::class);

        $migrator->rollback();
    }

    public function test_rolling_back_with_nothing_applied_is_a_no_op(): void
    {
        self::assertSame([], $this->migrator()->rollback());
    }

    public function test_a_file_returning_the_wrong_thing_is_reported(): void
    {
        file_put_contents($this->directory . '/0001_bad.php', '<?php return "not a migration";');

        $this->expectException(MigrationFailed::class);
        $this->expectExceptionMessageMatches('/must return a .*Migration instance/');

        $this->migrator()->migrate();
    }

    /**
     * The name is used to build a path, so it is pattern-checked first.
     */
    public function test_a_migration_name_cannot_traverse(): void
    {
        $this->db->executeSchema(sprintf(
            'CREATE TABLE %s (name TEXT PRIMARY KEY, batch INTEGER NOT NULL, applied_at TEXT NOT NULL)',
            Migrator::LEDGER_TABLE,
        ));

        $this->db->query(Migrator::LEDGER_TABLE)->insert([
            'name' => '../../../etc/passwd',
            'batch' => 1,
            'applied_at' => gmdate('c'),
        ]);

        $this->expectException(MigrationFailed::class);
        $this->expectExceptionMessageMatches('/names must look like/');

        $this->migrator()->rollback();
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->db, $this->directory);
    }

    private function write(string $name, string $table): void
    {
        file_put_contents($this->directory . '/' . $name . '.php', sprintf(
            <<<'PHP'
                <?php
                use PhpOrbit\Database\Connection;
                use PhpOrbit\Database\Migration;
                return new class implements Migration {
                    public function up(Connection $database): void {
                        $database->executeSchema('CREATE TABLE %1$s (id INTEGER PRIMARY KEY)');
                    }
                    public function down(Connection $database): void {
                        $database->executeSchema('DROP TABLE %1$s');
                    }
                };
                PHP,
            $table,
        ));
    }

    private function tableExists(string $table): bool
    {
        return $this->db->selectOne(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name",
            ['name' => $table],
        ) !== null;
    }
}
