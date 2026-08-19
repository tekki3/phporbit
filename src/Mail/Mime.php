<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

use InvalidArgumentException;

/**
 * The encoding rules a message has to obey on the wire.
 *
 * Kept apart from {@see Message} and {@see SmtpSession} so the fiddly parts —
 * which are where the bugs live — can be tested without building a message or
 * opening a socket.
 */
final class Mime
{
    /** SMTP forbids lines over 1000 bytes including CRLF (RFC 5321 §4.5.3.1). */
    public const MAX_LINE = 998;

    /**
     * Encodes a header value as an RFC 2047 word when it is not plain ASCII.
     *
     * Left alone when it is, because `Ada Lovelace` is more useful to a human
     * reading the raw message than `=?UTF-8?B?QWRhIExvdmVsYWNl?=`.
     */
    public static function encodeHeaderWord(string $value): string
    {
        self::assertNoInjection($value);

        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            // Quote it if it carries characters that are special in a header.
            return preg_match('/[()<>@,;:\\\\".\[\]]/', $value) === 1
                ? '"' . addcslashes($value, '"\\') . '"'
                : $value;
        }

        // Base64 rather than Q-encoding: one rule instead of two, and the
        // result is the same length for the scripts that actually need it.
        // 45 raw bytes encode to 60 base64 characters, leaving room for the
        // =?UTF-8?B?…?= wrapper inside a 76-column line.
        $chunks = [];

        foreach (str_split($value, 45) as $chunk) {
            $chunks[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }

        return implode("\r\n ", $chunks);
    }

    /**
     * A complete header line, folded if it is too long.
     */
    public static function headerLine(string $name, string $value): string
    {
        if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid header name.', $name));
        }

        self::assertNoInjection($value);

        return $name . ': ' . $value;
    }

    /**
     * Rejects the one thing that turns a value into extra headers.
     */
    public static function assertNoInjection(string $value): void
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException(
                'Header values may not contain CR, LF or NUL. Anything after one would be '
                . 'read as further headers, which is how a subject line becomes a second '
                . 'recipient.',
            );
        }
    }

    /**
     * Body text as quoted-printable, with CRLF endings.
     *
     * Keeps the common case legible in the raw message while staying inside
     * SMTP's line limit and surviving 8-bit content.
     */
    public static function quotedPrintable(string $body): string
    {
        return self::normaliseLineEndings(quoted_printable_encode($body));
    }

    /**
     * Binary as base64, wrapped to 76 columns.
     */
    public static function base64(string $body): string
    {
        return rtrim(chunk_split(base64_encode($body), 76, "\r\n"), "\r\n");
    }

    /**
     * Everything sent in DATA must use CRLF, whatever the source used.
     */
    public static function normaliseLineEndings(string $body): string
    {
        return (string) preg_replace('/\r\n|\r|\n/', "\r\n", $body);
    }

    /**
     * Doubles a leading dot on every line.
     *
     * A line consisting of a single "." ends the DATA command, so an
     * unescaped one lets a message body truncate itself — or, with the right
     * following bytes, inject a second message (RFC 5321 §4.5.2).
     */
    public static function stuffDots(string $body): string
    {
        return (string) preg_replace('/^\./m', '..', $body);
    }

    /**
     * A boundary that cannot occur in the parts it separates.
     */
    public static function boundary(): string
    {
        return '=_orbit_' . bin2hex(random_bytes(16));
    }
}
