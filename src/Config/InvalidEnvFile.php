<?php

declare(strict_types=1);

namespace PhpOrbit\Config;

use RuntimeException;

/**
 * Raised when a `.env` file cannot be parsed.
 *
 * Messages name the key and the line, never the value: this file is where
 * database passwords and API keys live, and an exception message travels into
 * logs, error pages and bug reports.
 */
final class InvalidEnvFile extends RuntimeException
{
    public static function at(string $path, int $line, string $problem): self
    {
        return new self(sprintf('%s line %d: %s', $path, $line, $problem));
    }
}
