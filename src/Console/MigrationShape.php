<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

/**
 * The kind of change a migration name implies.
 *
 * Inferred from long-standing convention — `create_articles_table`,
 * `add_slug_to_articles` — purely to pick a more useful starting file. The
 * inference is never load-bearing: every shape produces a valid migration, and
 * {@see Blank} is what you get when the name says nothing in particular.
 */
enum MigrationShape
{
    case CreateTable;
    case AlterTable;
    case Blank;
}
