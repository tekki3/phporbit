<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

/**
 * A single schema change.
 *
 * `down()` is required rather than optional so that writing a migration forces
 * a moment's thought about undoing it. When a change genuinely cannot be
 * reversed — dropping a column whose data is gone — throw
 * {@see IrreversibleMigration} from it, which documents that decision in the
 * migration itself instead of leaving an empty method that silently "succeeds".
 */
interface Migration
{
    public function up(Connection $database): void;

    public function down(Connection $database): void;
}
