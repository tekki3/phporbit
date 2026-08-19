<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

use RuntimeException;

/**
 * Thrown by a migration's `down()` when the change cannot be undone.
 *
 * Rolling back a batch containing one of these stops before it runs, so the
 * ledger never claims to have reversed something it did not.
 */
final class IrreversibleMigration extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self('This migration cannot be rolled back: ' . $reason);
    }
}
