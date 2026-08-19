<?php

declare(strict_types=1);

namespace PhpOrbit\Config;

/**
 * Parses a `.env` file into key/value pairs.
 *
 * Supported syntax, kept deliberately small so that reading a `.env` file
 * tells you exactly what it means:
 *
 *   KEY=value                 unquoted; trimmed, ends at ` #`
 *   KEY="value"               escapes (\n \t \r \\ \" \$) and ${VAR} expansion
 *   KEY='value'               entirely literal, no escapes, no expansion
 *   export KEY=value          the `export` prefix is ignored
 *   # comment                 whole-line comments
 *
 * Quoted values may span lines, which is what makes a PEM key or a multi-line
 * message usable without escaping every newline.
 *
 * `${VAR}` expands from keys already defined earlier in the file, or from the
 * real environment. A reference to something undefined is an error rather than
 * an empty string: silently expanding `${DB_PASSWORD}` to nothing produces a
 * connection failure far from its cause.
 */
final class EnvFile
{
    private const KEY = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @param array<string, string> $context values available to ${VAR} expansion
     * @return array<string, string>
     */
    public static function parse(string $contents, array $context = [], string $path = '.env'): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents);

        if ($lines === false) {
            throw new InvalidEnvFile(sprintf('%s could not be read as text.', $path));
        }

        $values = [];
        $total = count($lines);

        for ($index = 0; $index < $total; $index++) {
            $line = $lines[$index];
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // `export FOO=bar` is valid in a file meant to be `source`d too.
            if (str_starts_with($trimmed, 'export ')) {
                $trimmed = ltrim(substr($trimmed, 7));
            }

            $separator = strpos($trimmed, '=');

            if ($separator === false) {
                throw InvalidEnvFile::at($path, $index + 1, 'expected KEY=value');
            }

            $key = rtrim(substr($trimmed, 0, $separator));

            if (preg_match(self::KEY, $key) !== 1) {
                throw InvalidEnvFile::at(
                    $path,
                    $index + 1,
                    sprintf('"%s" is not a valid name; use letters, digits and underscores', $key),
                );
            }

            $rest = ltrim(substr($trimmed, $separator + 1));

            [$raw, $consumed, $quote] = self::readValue($rest, $lines, $index, $path);

            // Expansion sees earlier keys and the surrounding environment, so
            // later definitions cannot silently change earlier ones.
            $available = [...$context, ...$values];

            $values[$key] = match ($quote) {
                // Single quotes are wholly literal: no escapes, no expansion.
                "'" => $raw,
                // Expansion runs *before* unescaping, so `\${VAR}` is still
                // backslash-protected when the expander looks at it. Doing it
                // the other way round would turn `\$` into `$` first and then
                // expand the very thing that was escaped.
                '"' => self::unescape(self::expand($raw, $available, $path, $index + 1)),
                default => self::expand($raw, $available, $path, $index + 1),
            };

            $index += $consumed;
        }

        return $values;
    }

    /**
     * Reads one value, following a quoted string across lines if needed.
     *
     * Returns the value still escaped; interpreting it is the caller's job,
     * because the correct treatment depends on which quoting was used.
     *
     * @param list<string> $lines
     * @return array{0: string, 1: int, 2: string} raw value, extra lines used, quote character
     */
    private static function readValue(string $rest, array $lines, int $index, string $path): array
    {
        if ($rest === '') {
            return ['', 0, ''];
        }

        $quote = $rest[0];

        if ($quote !== '"' && $quote !== "'") {
            // Unquoted: a comment may follow, but only when preceded by
            // whitespace, so `pass#word` stays intact.
            $value = (string) preg_replace('/\s+#.*$/', '', $rest);

            return [rtrim($value), 0, ''];
        }

        $buffer = substr($rest, 1);
        $consumed = 0;

        while (true) {
            $end = self::findClosingQuote($buffer, $quote);

            if ($end !== null) {
                return [substr($buffer, 0, $end), $consumed, $quote];
            }

            $consumed++;

            if (!isset($lines[$index + $consumed])) {
                throw InvalidEnvFile::at($path, $index + 1, 'unterminated quoted value');
            }

            $buffer .= "\n" . $lines[$index + $consumed];
        }
    }

    /**
     * Locates the closing quote, skipping ones that are escaped.
     *
     * Only double quotes honour backslash escapes; inside single quotes a
     * backslash is an ordinary character.
     */
    private static function findClosingQuote(string $buffer, string $quote): ?int
    {
        $length = strlen($buffer);

        for ($i = 0; $i < $length; $i++) {
            if ($quote === '"' && $buffer[$i] === '\\') {
                $i++;

                continue;
            }

            if ($buffer[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }

    private static function unescape(string $value): string
    {
        return (string) preg_replace_callback(
            '/\\\\(.)/s',
            static fn (array $match): string => match ($match[1]) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'f' => "\f",
                'v' => "\v",
                '0' => "\0",
                '\\' => '\\',
                '"' => '"',
                '$' => '$',
                // An unknown escape is kept verbatim rather than swallowed, so
                // a Windows path in a value survives intact.
                default => '\\' . $match[1],
            },
            $value,
        );
    }

    /**
     * Expands `${NAME}` references.
     *
     * Only the braced form is recognised. A bare `$NAME` is left alone because
     * it appears constantly in passwords and shell snippets, and guessing
     * which one was meant is worse than requiring the explicit form.
     *
     * A backslash before the `$` suppresses expansion; the lookbehind checks
     * for it here, while the backslash itself is removed later by
     * {@see unescape()}.
     *
     * @param array<string, string> $available
     */
    private static function expand(string $value, array $available, string $path, int $line): string
    {
        if (!str_contains($value, '${')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/(?<!\\\\)\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $match) use ($available, $path, $line): string {
                $name = $match[1];

                if (array_key_exists($name, $available)) {
                    return $available[$name];
                }

                $fromEnvironment = getenv($name);

                if ($fromEnvironment !== false) {
                    return $fromEnvironment;
                }

                throw InvalidEnvFile::at($path, $line, sprintf(
                    '${%s} refers to a value that is not defined above it or in the environment',
                    $name,
                ));
            },
            $value,
        );
    }
}
