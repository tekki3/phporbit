<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Console;

use FilesystemIterator;
use InvalidArgumentException;
use PDO;
use PhpOrbit\Console\MigrationMaker;
use PhpOrbit\Console\MigrationShape;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migrator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * A generated migration is only useful if the migrator will run it, so the
 * last test here applies one to a real database and rolls it back.
 */
final class MigrationMakerTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/orbit-migration-' . bin2hex(random_bytes(6));
        mkdir($this->project . '/database/migrations', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->project);
    }

    // --- naming ---------------------------------------------------------------

    /**
     * The migrator orders by filename, so the prefix is what makes two
     * developers on separate branches merge deterministically.
     */
    public function test_the_filename_is_sortable_and_acceptable_to_the_migrator(): void
    {
        $made = $this->maker()->create('create_articles_table');

        self::assertMatchesRegularExpression('/^\d{14}_create_articles_table$/', $made->name);

        // Exactly the pattern Migrator::load() enforces.
        self::assertMatchesRegularExpression('/^[0-9]{4,}_[a-z0-9_]+$/', $made->name);
        self::assertFileExists($this->project . '/' . $made->path);
    }

    public function test_sequential_numbering_continues_from_the_last_migration(): void
    {
        touch($this->project . '/database/migrations/0007_something.php');

        self::assertStringStartsWith('0008_', $this->maker()->create('create_tags_table', sequential: true)->name);
    }

    public function test_sequential_numbering_starts_at_one_in_an_empty_project(): void
    {
        self::assertStringStartsWith('0001_', $this->maker()->create('create_tags_table', sequential: true)->name);
    }

    #[DataProvider('equivalentNames')]
    public function test_names_are_normalised_to_snake_case(string $name): void
    {
        self::assertStringEndsWith('_create_articles_table', $this->maker()->create($name)->name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentNames(): iterable
    {
        yield 'snake' => ['create_articles_table'];
        yield 'studly' => ['CreateArticlesTable'];
        yield 'spaced' => ['create articles table'];
        yield 'hyphenated' => ['create-articles-table'];
    }

    // --- shapes ---------------------------------------------------------------

    public function test_a_create_name_produces_a_create_table(): void
    {
        $made = $this->maker()->create('create_articles_table');

        self::assertSame(MigrationShape::CreateTable, $made->shape);
        self::assertSame('articles', $made->table);

        $source = (string) file_get_contents($this->project . '/' . $made->path);

        self::assertStringContainsString('CREATE TABLE articles', $source);
        self::assertStringContainsString('DROP TABLE articles', $source);
        // Portable across all three engines from the start.
        self::assertStringContainsString('autoIncrementPrimaryKey()', $source);
    }

    public function test_an_add_to_name_produces_an_alter_table(): void
    {
        $made = $this->maker()->create('add_slug_to_articles');

        self::assertSame(MigrationShape::AlterTable, $made->shape);
        self::assertSame('articles', $made->table);
        self::assertStringContainsString(
            'ALTER TABLE articles',
            (string) file_get_contents($this->project . '/' . $made->path),
        );
    }

    /**
     * The inference is a convenience, never load-bearing: a name that says
     * nothing still produces a valid migration.
     */
    public function test_an_unrecognised_name_produces_a_usable_blank(): void
    {
        $made = $this->maker()->create('backfill_search_index');

        self::assertSame(MigrationShape::Blank, $made->shape);
        self::assertNull($made->table);

        $source = (string) file_get_contents($this->project . '/' . $made->path);

        self::assertStringContainsString('public function up(Connection $database): void', $source);
        self::assertStringContainsString('public function down(Connection $database): void', $source);
        self::assertStringContainsString('IrreversibleMigration', $source);
    }

    public function test_an_explicit_table_overrides_the_inference(): void
    {
        $made = $this->maker()->create('backfill_search_index', table: 'documents');

        self::assertSame('documents', $made->table);
        self::assertStringContainsString(
            'ALTER TABLE documents',
            (string) file_get_contents($this->project . '/' . $made->path),
        );
    }

    #[DataProvider('generatedNames')]
    public function test_everything_it_writes_parses(string $name): void
    {
        $path = $this->project . '/' . $this->maker()->create($name)->path;

        $output = [];
        $status = 0;
        exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($path)), $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function generatedNames(): iterable
    {
        yield 'create' => ['create_articles_table'];
        yield 'alter' => ['add_slug_to_articles'];
        yield 'blank' => ['backfill_search_index'];
    }

    // --- refusals -------------------------------------------------------------

    /**
     * A path separator means the caller was aiming somewhere. Quietly
     * rewriting "../evil" to "evil" would hide that rather than answer it.
     */
    #[DataProvider('pathLikeNames')]
    public function test_it_refuses_a_name_that_looks_like_a_path(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not by path/');

        $this->maker()->create($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathLikeNames(): iterable
    {
        yield 'traversal' => ['../evil'];
        yield 'nested' => ['schema/articles'];
        yield 'windows' => ['..\\evil'];
        yield 'dots' => ['create..articles'];
    }

    #[DataProvider('unusableNames')]
    public function test_it_refuses_a_name_the_migrator_could_never_run(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->maker()->create($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableNames(): iterable
    {
        // The migrator's pattern is <digits>_<lowercase words>, so a name that
        // starts with a digit or is entirely punctuation cannot work.
        yield 'leading digits' => ['123'];
        yield 'punctuation only' => ['!!!'];
        yield 'empty' => [''];
    }

    /**
     * The table name reaches the SQL directly — no driver can bind an
     * identifier — so it is validated rather than escaped.
     */
    public function test_it_refuses_a_table_name_that_is_not_an_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid table name/');

        $this->maker()->create('create_things_table', table: 'a; DROP TABLE users');
    }

    public function test_it_refuses_to_overwrite_without_force(): void
    {
        $made = $this->maker()->create('create_articles_table');
        file_put_contents($this->project . '/' . $made->path, '<?php // mine');

        try {
            // Same second, same slug — the same filename.
            $this->maker()->create('create_articles_table');
            self::fail('an existing migration should not be overwritten');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('--force', $e->getMessage());
        }

        self::assertStringContainsString(
            '// mine',
            (string) file_get_contents($this->project . '/' . $made->path),
        );
    }

    // --- it actually runs -----------------------------------------------------

    /**
     * Generates a migration, applies it to a real database, and rolls it back.
     */
    public function test_a_generated_migration_applies_and_reverses(): void
    {
        $this->maker()->create('create_widgets_table');

        $database = new Connection(new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]));

        $migrator = new Migrator($database, $this->project . '/database/migrations');

        self::assertCount(1, $migrator->migrate());
        self::assertTrue($this->tableExists($database, 'widgets'));

        // The generated column is usable, not just present.
        $id = $database->query('widgets')->insert(['created_at' => gmdate('c')]);
        self::assertGreaterThan(0, $id);

        self::assertCount(1, $migrator->rollback());
        self::assertFalse($this->tableExists($database, 'widgets'));
    }

    // --- helpers --------------------------------------------------------------

    private function maker(): MigrationMaker
    {
        return new MigrationMaker($this->project);
    }

    private function tableExists(Connection $database, string $table): bool
    {
        return $database->selectOne(
            'SELECT name FROM sqlite_master WHERE type = :type AND name = :name',
            ['type' => 'table', 'name' => $table],
        ) !== null;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
