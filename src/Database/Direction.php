<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

/**
 * Sort direction as an enum, so "ASC"/"DESC" is never a caller-supplied string
 * concatenated into SQL.
 */
enum Direction
{
    case Ascending;
    case Descending;

    public function sql(): string
    {
        return match ($this) {
            self::Ascending => 'ASC',
            self::Descending => 'DESC',
        };
    }
}
