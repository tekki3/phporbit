<?php

declare(strict_types=1);

namespace PhpOrbit\Config;

use RuntimeException;

/**
 * Raised when a required setting is absent or the wrong shape.
 *
 * Thrown during boot, so a misconfigured application refuses to start rather
 * than serving traffic until it happens to touch the missing setting.
 */
final class MissingConfiguration extends RuntimeException
{
    public static function absent(string $key): self
    {
        return new self(sprintf(
            'Required setting "%s" is not set. Add it to your .env file or the environment.',
            $key,
        ));
    }

    public static function empty(string $key): self
    {
        return new self(sprintf(
            'Required setting "%s" is present but empty. A blank value is not usable here.',
            $key,
        ));
    }

    /**
     * Reports a type mismatch without quoting the value.
     */
    public static function notOfType(string $key, string $expected, string $accepted): self
    {
        return new self(sprintf(
            'Setting "%s" is not a valid %s. Accepted values: %s.',
            $key,
            $expected,
            $accepted,
        ));
    }
}
