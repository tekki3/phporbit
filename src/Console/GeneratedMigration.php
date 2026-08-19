<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

/**
 * What `make:migration` produced.
 */
final class GeneratedMigration
{
    public function __construct(
        /** The ledger name, e.g. 20260811143012_create_articles_table */
        public readonly string $name,
        /** Project-relative, e.g. database/migrations/20260811143012_create_articles_table.php */
        public readonly string $path,
        /** The shape inferred from the name */
        public readonly MigrationShape $shape,
        /** The table it operates on, when one could be inferred */
        public readonly ?string $table,
    ) {
    }
}
