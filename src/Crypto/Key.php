<?php

declare(strict_types=1);

namespace PhpOrbit\Crypto;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * A 256-bit secret.
 *
 * Wrapped in an object rather than passed around as a string so that it cannot
 * be printed, logged or serialised by accident — the three ways application
 * keys actually escape. `var_dump`, `print_r` and a stack trace all show a
 * placeholder, and serialisation is refused outright.
 */
final class Key
{
    public const BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

    /** Marks the encoding, so a mistyped key is obvious rather than mysterious. */
    private const PREFIX = 'base64:';

    private string $bytes;

    public function __construct(#[SensitiveParameter] string $bytes)
    {
        if (strlen($bytes) !== self::BYTES) {
            throw new InvalidArgumentException(sprintf(
                'An application key must be exactly %d bytes, got %d. Generate one with '
                . '`orbit key:generate`.',
                self::BYTES,
                strlen($bytes),
            ));
        }

        $this->bytes = $bytes;
    }

    public static function generate(): self
    {
        return new self(random_bytes(self::BYTES));
    }

    /**
     * Reads the `base64:…` form stored in configuration.
     */
    public static function fromString(#[SensitiveParameter] string $value): self
    {
        $value = trim($value);

        if (!str_starts_with($value, self::PREFIX)) {
            throw new InvalidArgumentException(
                'An application key must start with "base64:". Generate one with `orbit key:generate`.',
            );
        }

        $decoded = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if ($decoded === false) {
            throw new InvalidArgumentException('The application key is not valid base64.');
        }

        return new self($decoded);
    }

    /**
     * The `base64:…` form, for writing into configuration.
     *
     * Deliberately a method with a blunt name rather than `__toString()`: a key
     * that stringifies silently ends up interpolated into a log line.
     */
    public function exportForConfiguration(): string
    {
        return self::PREFIX . base64_encode($this->bytes);
    }

    /**
     * The raw bytes, for the primitives that need them.
     *
     * @internal
     */
    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * A distinct key for a distinct purpose, derived from this one.
     *
     * Using one key for both encryption and authentication is a classic
     * mistake: the two algorithms make different assumptions, and a weakness in
     * either can then compromise the other. Deriving with a label keeps them
     * independent while there is still only one secret to manage.
     */
    public function derive(string $purpose): self
    {
        return new self(sodium_crypto_generichash(
            $purpose,
            $this->bytes,
            self::BYTES,
        ));
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['key' => '<redacted>'];
    }

    public function __toString(): string
    {
        return '<redacted>';
    }

    /**
     * Refuses serialisation: a serialised key lands in a session file, a cache
     * entry or a queue payload, all of which outlive the process.
     *
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new InvalidArgumentException('An application key must not be serialised.');
    }

    // There is deliberately no destructor calling sodium_memzero().
    //
    // It would zero this one buffer while PHP has already copied the bytes
    // several times over — base64_encode, hash_hmac and the sodium calls each
    // take their own — so the key remains in memory regardless. Wiping one copy
    // buys a false sense of having scrubbed the process, and sodium_memzero()
    // nulls the reference it is given, which a typed property cannot hold.
    //
    // The defences that do work are above: the key cannot be printed, cast to a
    // string, or serialised.
}
