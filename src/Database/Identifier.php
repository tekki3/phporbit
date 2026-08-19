<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

use InvalidArgumentException;

/**
 * Validates and quotes table and column names.
 *
 * No driver can bind an identifier — placeholders only work for values — so a
 * table or column name always ends up concatenated into the SQL. That makes it
 * the one place injection is still possible, and the reason names are matched
 * against a strict pattern rather than escaped: escaping invites the question
 * "escaped well enough?", while a whitelist has one answer.
 *
 * Double quotes are the SQL standard delimiter and work on SQLite and
 * PostgreSQL. MySQL needs `ANSI_QUOTES` enabled, or backticks.
 */
final class Identifier
{
    private const PART = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * Quotes `column`, `table.column`, or `*`.
     *
     * The delimiter varies by engine — double quotes are the SQL standard and
     * work on SQLite and PostgreSQL, while MySQL needs backticks unless
     * `ANSI_QUOTES` is set. The *validation* does not vary, and that is the part
     * that matters: whichever delimiter is used, only plain names get through,
     * so there is nothing for a delimiter to have to escape.
     */
    public static function quote(string $name, Driver $driver = Driver::Sqlite): string
    {
        $delimiter = $driver->delimiter();

        $name = trim($name);

        if ($name === '*') {
            return '*';
        }

        $parts = explode('.', $name);

        if (count($parts) > 2) {
            throw new InvalidArgumentException(sprintf(
                'Identifier "%s" has too many parts; use "column" or "table.column".',
                $name,
            ));
        }

        $quoted = [];

        foreach ($parts as $part) {
            // A trailing ".*" is the one wildcard that makes sense qualified.
            if ($part === '*' && count($parts) === 2) {
                $quoted[] = '*';

                continue;
            }

            if (preg_match(self::PART, $part) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Identifier "%s" is not a plain table or column name. Identifiers cannot be '
                    . 'bound as parameters, so only letters, digits and underscores are accepted.',
                    $name,
                ));
            }

            $quoted[] = $delimiter . $part . $delimiter;
        }

        return implode('.', $quoted);
    }

    public static function isValid(string $name): bool
    {
        try {
            self::quote($name);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
