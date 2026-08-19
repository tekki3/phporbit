<?php

declare(strict_types=1);

namespace PhpOrbit\Security;

use PhpOrbit\Session\Session;

/**
 * Per-session CSRF tokens.
 *
 * The token is bound to the session rather than to a single form, so a user
 * with several tabs open does not invalidate their own submissions.
 */
final class Csrf
{
    public const SESSION_KEY = '_csrf_token';
    public const FIELD_NAME = '_token';
    public const HEADER_NAME = 'X-CSRF-Token';

    /**
     * Returns the session's token, minting one on first use.
     */
    public static function token(Session $session): string
    {
        $existing = $session->get(self::SESSION_KEY);

        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $session->set(self::SESSION_KEY, $token);

        return $token;
    }

    /**
     * Compares a presented token against the session's.
     *
     * `hash_equals` runs in time independent of where the two strings first
     * differ; `===` would leak the correct prefix through timing.
     */
    public static function isValid(Session $session, ?string $presented): bool
    {
        if ($presented === null || $presented === '') {
            return false;
        }

        $expected = $session->get(self::SESSION_KEY);

        if ($expected === null || $expected === '') {
            return false;
        }

        return hash_equals($expected, $presented);
    }

    /**
     * Discards the current token so the next read mints a fresh one.
     *
     * Worth doing on login alongside session regeneration.
     */
    public static function rotate(Session $session): void
    {
        $session->remove(self::SESSION_KEY);
    }

    /**
     * A ready-made hidden input for a form.
     *
     * The token is attribute-escaped even though it is hex — templates get
     * copied and repurposed, and the habit should survive that.
     */
    public static function field(Session $session): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD_NAME,
            Escaper::attribute(self::token($session)),
        );
    }
}
