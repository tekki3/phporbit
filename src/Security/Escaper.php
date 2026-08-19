<?php

declare(strict_types=1);

namespace PhpOrbit\Security;

use InvalidArgumentException;

/**
 * Context-aware output escaping.
 *
 * There is no single "escape" function that is correct everywhere: a value
 * safe inside an HTML element is still dangerous inside an unquoted attribute,
 * a script block or a URL. Each context therefore gets its own method, and the
 * template engine picks one based on where the value lands.
 */
final class Escaper
{
    private const HTML_FLAGS = ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5;

    /**
     * Text inside an HTML element.
     */
    public static function html(string $value): string
    {
        return htmlspecialchars($value, self::HTML_FLAGS, 'UTF-8');
    }

    /**
     * An HTML attribute value.
     *
     * Escapes every non-alphanumeric ASCII character as a hex entity, which
     * stays safe even if the template author forgot the surrounding quotes.
     */
    public static function attribute(string $value): string
    {
        $escaped = preg_replace_callback(
            '/[^a-zA-Z0-9,._\-]/u',
            static function (array $match): string {
                $char = (string) $match[0];

                if (strlen($char) === 1) {
                    return sprintf('&#x%02X;', ord($char));
                }

                return sprintf('&#x%04X;', mb_ord($char, 'UTF-8'));
            },
            $value,
        );

        // preg returns null only when the subject is not valid UTF-8. Escaping
        // is a safety operation, so a failure must surface rather than quietly
        // yield an empty string that looks like a successful escape.
        if ($escaped === null) {
            throw new InvalidArgumentException('Cannot escape a string that is not valid UTF-8.');
        }

        return $escaped;
    }

    /**
     * A string literal inside a <script> block.
     *
     * Encodes as JSON — including the surrounding quotes — because manual
     * backslash escaping reliably misses a case. Callers must not add their
     * own quotes around the result.
     */
    public static function js(string $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * A value used as a query-string parameter or path segment.
     */
    public static function url(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * A complete URL destined for href/src.
     *
     * Only http, https and mailto survive; anything else — javascript:, data:,
     * vbscript: — collapses to '#'. Returning a harmless value rather than
     * throwing keeps a hostile link from taking down the whole page render.
     */
    public static function urlAttribute(string $url): string
    {
        $scheme = parse_url(trim($url), PHP_URL_SCHEME);

        if ($scheme !== null && $scheme !== false) {
            $allowed = ['http', 'https', 'mailto'];

            if (!in_array(strtolower((string) $scheme), $allowed, true)) {
                return '#';
            }
        }

        return self::attribute($url);
    }
}
