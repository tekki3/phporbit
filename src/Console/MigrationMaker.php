<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Writes a migration file.
 *
 * The filename carries a sortable prefix because {@see \PhpOrbit\Database\Migrator}
 * orders by filename — that is what makes two developers adding migrations on
 * separate branches produce a deterministic order once merged, rather than one
 * that depends on class discovery.
 *
 * A timestamp is the default prefix for exactly that reason: two people working
 * at the same time get different numbers without coordinating. `--sequential`
 * gives the `0001`, `0002` counter style instead, which reads better in a small
 * repository where collisions are not a concern.
 *
 * The name also decides the starting contents — `create_articles_table` gets a
 * CREATE TABLE, `add_slug_to_articles` an ALTER — but that inference is only a
 * convenience. Every shape produces a valid, portable migration.
 */
final class MigrationMaker
{
    /** @var Closure(string): void */
    private readonly Closure $report;

    /**
     * @param string $root the project root, containing database/migrations/
     * @param (Closure(string): void)|null $report
     */
    public function __construct(
        private readonly string $root,
        ?Closure $report = null,
    ) {
        $this->report = $report ?? static function (string $line): void {
        };
    }

    /**
     * @param string      $name       `create_articles_table`, `CreateArticles`, `add_slug_to_articles`
     * @param string|null $table      overrides whatever the name implies
     * @param bool        $sequential number the file 0001, 0002 … instead of by timestamp
     */
    public function create(
        string $name,
        ?string $table = null,
        bool $sequential = false,
        bool $force = false,
    ): GeneratedMigration {
        // A name is words, so punctuation and casing are normalised freely —
        // "CreateArticles" and "create articles" are the same request. Path
        // separators are not: they mean the caller was aiming somewhere, and
        // quietly rewriting "../evil" to "evil" would hide that rather than
        // answer it. The file could not escape the directory either way, since
        // the path is built from the normalised slug.
        if (preg_match('#[/\\\\]|\.\.#', $name) === 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid migration name "%s". A migration is named in words, not by path — '
                . 'try create_articles_table.',
                $name,
            ));
        }

        $slug = $this->toSnakeCase($name);

        // The Migrator refuses anything else, so refuse it here rather than
        // writing a file that can never run.
        if (preg_match('/^[a-z][a-z0-9_]*$/', $slug) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid migration name "%s". Use words, e.g. create_articles_table or '
                . 'add_slug_to_articles.',
                $name,
            ));
        }

        [$shape, $inferredTable] = $this->interpret($slug);
        $table ??= $inferredTable;

        if ($table !== null && preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid table name "%s". Identifiers may contain letters, digits and underscores.',
                $table,
            ));
        }

        // An explicit --table on a name that implied nothing still deserves the
        // more useful starting file.
        if ($shape === MigrationShape::Blank && $table !== null) {
            $shape = MigrationShape::AlterTable;
        }

        $directory = $this->root . '/database/migrations';
        $this->ensureDirectory($directory);

        $migrationName = $this->prefix($directory, $sequential) . '_' . $slug;
        $relative = 'database/migrations/' . $migrationName . '.php';
        $path = $this->root . '/' . $relative;

        if (is_file($path) && !$force) {
            throw new RuntimeException(sprintf('%s already exists. Pass --force to overwrite it.', $relative));
        }

        if (@file_put_contents($path, $this->source($shape, $table)) === false) {
            throw new RuntimeException(sprintf('Cannot write "%s".', $relative));
        }

        ($this->report)('Created ' . $relative);

        return new GeneratedMigration($migrationName, $relative, $shape, $table);
    }

    /**
     * Reads the conventional name patterns.
     *
     * @return array{0: MigrationShape, 1: string|null}
     */
    private function interpret(string $slug): array
    {
        if (preg_match('/^create_(.+?)(?:_table)?$/', $slug, $match) === 1) {
            return [MigrationShape::CreateTable, $match[1]];
        }

        // add_slug_to_articles, drop_legacy_from_users, rename_x_on_orders
        if (preg_match('/^(?:add|drop|remove|rename|change|alter|update)_.+?_(?:to|from|on|in)_(.+)$/', $slug, $match) === 1) {
            return [MigrationShape::AlterTable, $match[1]];
        }

        return [MigrationShape::Blank, null];
    }

    /**
     * The next sortable prefix.
     *
     * Sequential numbering reads the directory so it continues where the last
     * migration left off; a timestamp needs nothing but the clock.
     */
    private function prefix(string $directory, bool $sequential): string
    {
        if (!$sequential) {
            return date('YmdHis');
        }

        $highest = 0;

        foreach (glob($directory . '/*.php') ?: [] as $file) {
            if (preg_match('/^(\d+)_/', basename($file), $match) === 1) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        return str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

    private function source(MigrationShape $shape, ?string $table): string
    {
        return match ($shape) {
            MigrationShape::CreateTable => $this->createTableSource((string) $table),
            MigrationShape::AlterTable => $this->alterTableSource((string) $table),
            MigrationShape::Blank => $this->blankSource(),
        };
    }

    private function createTableSource(string $table): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use PhpOrbit\\Database\\Connection;
            use PhpOrbit\\Database\\Migration;

            return new class implements Migration {
                public function up(Connection \$database): void
                {
                    // The primary key is the one part of this the three engines spell
                    // differently. Index VARCHAR rather than TEXT columns: MySQL cannot
                    // build an index on TEXT without a prefix length.
                    \$database->executeSchema(sprintf(
                        'CREATE TABLE {$table} (
                            id %s,
                            created_at TEXT NOT NULL
                        )',
                        \$database->driver()->autoIncrementPrimaryKey(),
                    ));
                }

                public function down(Connection \$database): void
                {
                    \$database->executeSchema('DROP TABLE {$table}');
                }
            };

            PHP;
    }

    private function alterTableSource(string $table): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use PhpOrbit\\Database\\Connection;
            use PhpOrbit\\Database\\Migration;

            return new class implements Migration {
                public function up(Connection \$database): void
                {
                    \$database->executeSchema('ALTER TABLE {$table} ADD COLUMN example TEXT NULL');
                }

                public function down(Connection \$database): void
                {
                    // SQLite gained DROP COLUMN in 3.35; older builds need the
                    // copy-and-rename dance instead.
                    \$database->executeSchema('ALTER TABLE {$table} DROP COLUMN example');
                }
            };

            PHP;
    }

    private function blankSource(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            use PhpOrbit\Database\Connection;
            use PhpOrbit\Database\IrreversibleMigration;
            use PhpOrbit\Database\Migration;

            return new class implements Migration {
                public function up(Connection $database): void
                {
                    // $database->executeSchema('...');
                }

                /**
                 * down() is required rather than optional, so writing a migration forces
                 * a moment's thought about undoing it. When a change genuinely cannot be
                 * reversed, say so:
                 *
                 *     throw IrreversibleMigration::because('the old values were not retained.');
                 *
                 * That records the decision here, instead of leaving an empty method that
                 * silently "succeeds" and leaves the schema wrong.
                 */
                public function down(Connection $database): void
                {
                    // $database->executeSchema('...');
                }
            };

            PHP;
    }

    /**
     * `CreateArticlesTable` and `create articles table` both become
     * `create_articles_table`.
     */
    private function toSnakeCase(string $value): string
    {
        $value = trim($value);
        $value = (string) preg_replace('/(?<!^)([A-Z])/', '_$1', $value);
        $value = (string) preg_replace('/[^A-Za-z0-9]+/', '_', $value);
        $value = (string) preg_replace('/_+/', '_', $value);

        return strtolower(trim($value, '_'));
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0o755, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Cannot create directory "%s".', $path));
        }
    }
}
