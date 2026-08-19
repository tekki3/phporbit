<?php

declare(strict_types=1);

namespace PhpOrbit\Log;

use ValueError;

enum Level: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function severity(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Warning => 2,
            self::Error => 3,
        };
    }

    /**
     * Resolves a level written in configuration.
     *
     * An unrecognised name throws rather than falling back to a default: a
     * typo'd `LOG_LEVEL=warn` that silently became `debug` would put request
     * detail into production logs, and one that became `error` would hide the
     * warnings someone deliberately asked for.
     */
    public static function fromName(string $name): self
    {
        return self::tryFrom(strtolower(trim($name))) ?? throw new ValueError(sprintf(
            'Unknown log level "%s". Use one of: %s.',
            $name,
            implode(', ', array_map(static fn (self $l): string => $l->value, self::cases())),
        ));
    }
}
