<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

use RuntimeException;
use Throwable;

final class MigrationFailed extends RuntimeException
{
    public static function running(string $name, Throwable $cause): self
    {
        return new self(
            sprintf('Migration "%s" failed and was rolled back: %s', $name, $cause->getMessage()),
            0,
            $cause,
        );
    }

    public static function invalidFile(string $path, string $reason): self
    {
        return new self(sprintf('Migration file "%s" is unusable: %s', $path, $reason));
    }
}
