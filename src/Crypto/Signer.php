<?php

declare(strict_types=1);

namespace PhpOrbit\Crypto;

/**
 * Tamper-proofing for values that are not secret.
 *
 * An unsubscribe link, a password-reset URL, a preference cookie: the recipient
 * may read the value, they just must not be able to change it. Signing is the
 * right tool there, and encrypting instead would hide something that was never
 * secret while costing the same.
 *
 * Uses a key derived from the application key, so signatures and ciphertexts
 * never share key material even though there is one secret to manage.
 */
final class Signer
{
    private readonly Key $key;

    /** @var list<Key> */
    private readonly array $retiredKeys;

    /**
     * @param list<Key> $retiredKeys accepted when verifying, never used to sign
     */
    public function __construct(Key $key, array $retiredKeys = [])
    {
        $this->key = $key->derive('phporbit:sign:v1');

        $this->retiredKeys = array_map(
            static fn (Key $retired): Key => $retired->derive('phporbit:sign:v1'),
            $retiredKeys,
        );
    }

    /**
     * Signs a value, optionally with a lifetime.
     *
     * A signature with no expiry is valid until the key changes, which for a
     * password-reset link is far too long. Pass `$ttlSeconds` for anything that
     * should stop working.
     */
    public function sign(string $value, ?int $ttlSeconds = null): string
    {
        $expiresAt = $ttlSeconds === null ? '' : (string) (time() + $ttlSeconds);
        $payload = $expiresAt . ':' . $value;

        return self::encode($payload) . '.' . self::encode($this->mac($payload, $this->key));
    }

    /**
     * Returns the value, or null if the signature is wrong or it has expired.
     */
    public function verify(string $signed): ?string
    {
        $parts = explode('.', $signed);

        if (count($parts) !== 2) {
            return null;
        }

        $payload = self::decode($parts[0]);
        $signature = self::decode($parts[1]);

        if ($payload === null || $signature === null) {
            return null;
        }

        if (!$this->matchesAnyKey($payload, $signature)) {
            return null;
        }

        $separator = strpos($payload, ':');

        if ($separator === false) {
            return null;
        }

        $expiresAt = substr($payload, 0, $separator);
        $value = substr($payload, $separator + 1);

        // Checked after the signature, never before: an expiry test on
        // unverified input tells an attacker their forgery was well-formed.
        if ($expiresAt !== '' && (int) $expiresAt < time()) {
            return null;
        }

        return $value;
    }

    /**
     * @throws DecryptionFailed when the signature is wrong or expired
     */
    public function verifyOrFail(string $signed): string
    {
        return $this->verify($signed) ?? throw DecryptionFailed::create();
    }

    private function matchesAnyKey(string $payload, string $signature): bool
    {
        $valid = false;

        foreach ([$this->key, ...$this->retiredKeys] as $key) {
            // No early return: every key is checked either way, so the time
            // taken does not reveal which one matched, or that any did.
            $valid = hash_equals($this->mac($payload, $key), $signature) || $valid;
        }

        return $valid;
    }

    private function mac(string $payload, Key $key): string
    {
        return hash_hmac('sha256', $payload, $key->bytes(), true);
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): ?string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
