<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use InvalidArgumentException;

/**
 * Turns a name typed at the command line into class-name segments.
 *
 * Shared by the generators rather than copied into each, because the rule it
 * enforces is a safety property: a name arriving from a shell argument must
 * never be able to place a file outside the directory the generator owns. One
 * copy that validates and one that sanitises would make the laxer one the
 * weakness, so there is only one.
 */
final class PhpName
{
    /**
     * Words PHP will not accept as a class or namespace segment.
     *
     * Checked because the alternative is writing a file that cannot be loaded:
     * `class Match {}` and `namespace App\List;` are both parse errors, and a
     * refusal naming the word is far easier to act on than a syntax error in
     * generated code. Multi-word keywords (`include_once`) cannot survive the
     * StudlyCase check, so they are not listed.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone',
        'const', 'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty',
        'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'extends', 'final',
        'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements',
        'include', 'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match', 'namespace',
        'new', 'or', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'return',
        'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield',
        'int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object',
        'mixed', 'never', 'self', 'parent',
    ];

    /**
     * @param string $noun     what is being named, for the message: "class", "form"
     * @param string $examples a few valid names, so the message answers rather than only refuses
     * @return non-empty-list<string>
     */
    public static function segments(string $name, string $noun, string $examples): array
    {
        $segments = preg_split('#[/\\\\]+#', trim($name, "/\\ \t")) ?: [];
        $parsed = [];

        foreach ($segments as $segment) {
            if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $segment) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid %s name "%s". Use StudlyCase, optionally nested: %s.',
                    $noun,
                    $name,
                    $examples,
                ));
            }

            if (in_array(strtolower($segment), self::RESERVED, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid %s name "%s": "%s" is a reserved word in PHP and cannot name a '
                    . 'class or a namespace.',
                    $noun,
                    $name,
                    $segment,
                ));
            }

            $parsed[] = $segment;
        }

        if ($parsed === []) {
            throw new InvalidArgumentException(sprintf(
                'A %s name is required, for example: %s',
                $noun,
                explode(',', $examples)[0],
            ));
        }

        return $parsed;
    }

    /**
     * `UserProfile` -> `user-profile`, for a template name or a route path.
     */
    public static function toKebabCase(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $value));
    }

    /**
     * `UserProfile` -> `User Profile`, for a default heading.
     */
    public static function toTitle(string $value): string
    {
        return trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $value));
    }
}
