<?php

declare(strict_types=1);

namespace PhpOrbit\Auth;

use InvalidArgumentException;

/**
 * Password hashing with a deliberately slow algorithm.
 *
 * Argon2id when the build provides it, bcrypt otherwise. Both are designed to
 * be expensive; a general-purpose hash such as SHA-256 is not, and a modern
 * GPU tries billions of those per second against a stolen table.
 *
 * `password_verify()` is constant-time for a given hash, so comparing here
 * leaks nothing about how much of a guess was right.
 */
final class PasswordHasher
{
    /**
     * A hash of a value nobody will guess, used to burn the same CPU time as a
     * real verification when no user matched. See {@see dummyVerify()}.
     */
    private readonly string $decoyHash;

    /** @var array<string, int> */
    private readonly array $options;

    private readonly string $algorithm;

    public function __construct(?string $algorithm = null)
    {
        $this->algorithm = $algorithm ?? (defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);

        $this->options = $this->algorithm === PASSWORD_BCRYPT
            ? ['cost' => 12]
            : [];

        $this->decoyHash = password_hash(bin2hex(random_bytes(16)), $this->algorithm, $this->options);
    }

    /**
     * The maximum password length accepted.
     *
     * bcrypt silently ignores everything past 72 bytes, which would make two
     * different long passwords interchangeable. Rejecting is better than
     * truncating.
     */
    public const MAX_LENGTH = 72;

    public function hash(string $plain): string
    {
        if ($plain === '') {
            throw new InvalidArgumentException('A password cannot be empty.');
        }

        if (strlen($plain) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A password may be at most %d bytes; longer values are silently truncated by bcrypt.',
                self::MAX_LENGTH,
            ));
        }

        // Since PHP 8 this either returns a hash or throws; there is no longer
        // a falsy return to guard against.
        return password_hash($plain, $this->algorithm, $this->options);
    }

    public function verify(string $plain, string $hash): bool
    {
        if ($plain === '' || $hash === '') {
            return false;
        }

        return password_verify($plain, $hash);
    }

    /**
     * Spends the same time a real verification would, and always fails.
     *
     * Without this, "no such user" returns noticeably faster than "wrong
     * password", which turns a login form into a way to enumerate accounts.
     */
    public function dummyVerify(string $plain): bool
    {
        password_verify($plain === '' ? 'placeholder' : $plain, $this->decoyHash);

        return false;
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }
}
