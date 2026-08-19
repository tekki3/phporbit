<?php

declare(strict_types=1);

namespace PhpOrbit\Auth;

use PhpOrbit\Database\Connection;

/**
 * Rate limits login attempts.
 *
 * A slow password hash raises the cost of an offline attack on a stolen table;
 * it does nothing about someone working through a password list against the
 * live login form. That needs a limit on attempts, which is what this is.
 *
 * Attempts are keyed on the email *and* the client address together. Keying on
 * email alone would let anyone lock a known account out at will; keying on
 * address alone lets a botnet spread its guesses across hosts. The pair makes
 * both harder without either failure mode.
 */
final class LoginThrottle
{
    public const TABLE = 'auth_attempts';

    public function __construct(
        private readonly Connection $database,
        private readonly int $maxAttempts = 5,
        private readonly int $windowSeconds = 900,
    ) {
    }

    public function tooManyAttempts(string $key): bool
    {
        return $this->attempts($key) >= $this->maxAttempts;
    }

    public function attempts(string $key): int
    {
        return $this->database->query(self::TABLE)
            ->where('attempt_key', '=', $this->fingerprint($key))
            ->where('attempted_at', '>=', time() - $this->windowSeconds)
            ->count();
    }

    /**
     * How long until the next attempt is permitted, in seconds.
     */
    public function retryAfter(string $key): int
    {
        $oldest = $this->database->query(self::TABLE)
            ->where('attempt_key', '=', $this->fingerprint($key))
            ->where('attempted_at', '>=', time() - $this->windowSeconds)
            ->orderBy('attempted_at')
            ->value('attempted_at');

        if ($oldest === null) {
            return 0;
        }

        return max(0, (int) $oldest + $this->windowSeconds - time());
    }

    public function record(string $key): void
    {
        $this->database->query(self::TABLE)->insert([
            'attempt_key' => $this->fingerprint($key),
            'attempted_at' => time(),
        ]);
    }

    /**
     * Clears the record for a key, called after a successful login.
     */
    public function clear(string $key): void
    {
        $this->database->query(self::TABLE)
            ->where('attempt_key', '=', $this->fingerprint($key))
            ->delete();
    }

    /**
     * Removes attempts that have aged out of the window.
     */
    public function purge(): int
    {
        return $this->database->query(self::TABLE)
            ->where('attempted_at', '<', time() - $this->windowSeconds)
            ->delete();
    }

    /**
     * Stores a hash of the key rather than the key itself.
     *
     * The key contains an email address and an IP. This table exists to slow
     * attackers down, not to become a log of who tried to sign in from where.
     */
    private function fingerprint(string $key): string
    {
        return hash('sha256', $key);
    }
}
