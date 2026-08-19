<?php

declare(strict_types=1);

namespace PhpOrbit\Crypto;

use SensitiveParameter;
use Throwable;

/**
 * Authenticated encryption, with no way to ask for anything weaker.
 *
 * XChaCha20-Poly1305: every ciphertext carries a tag that is verified before a
 * single byte is returned, so tampering is detected rather than decrypted into
 * something the application then trusts. There is no mode selector and no way
 * to disable authentication — unauthenticated encryption is not a trade-off
 * this framework offers, because the times it is genuinely safe are rare and
 * the times it looks safe are not.
 *
 * The 24-byte nonce is what makes this the right choice over AES-GCM here: at
 * that size a random nonce per message has no practical collision risk, so
 * there is no counter to persist and nothing to get wrong under a worker.
 *
 * Safe to share across a worker's requests — it holds keys and nothing else.
 */
final class Encrypter
{
    /** Distinguishes the format, so it can change without ambiguity later. */
    private const VERSION = 'v1';

    private readonly Key $key;

    /** @var list<Key> */
    private readonly array $retiredKeys;

    /**
     * @param list<Key> $retiredKeys accepted when decrypting, never used to encrypt
     */
    public function __construct(Key $key, array $retiredKeys = [])
    {
        // Derived rather than used directly, so the same APP_KEY can also back
        // the signer without the two sharing key material.
        $this->key = $key->derive('phporbit:encrypt:v1');

        $this->retiredKeys = array_map(
            static fn (Key $retired): Key => $retired->derive('phporbit:encrypt:v1'),
            $retiredKeys,
        );
    }

    /**
     * Encrypts a value.
     *
     * `$context` is authenticated but not encrypted: it is not recoverable from
     * the token, and decryption fails unless the same value is supplied again.
     * Binding a ciphertext to where it belongs — `'users.email:42'` — is what
     * stops an attacker who can write to the database from moving one row's
     * encrypted value into another row and having it accepted.
     */
    public function encrypt(#[SensitiveParameter] string $plaintext, string $context = ''): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $context,
            $nonce,
            $this->key->bytes(),
        );

        return self::VERSION . '.' . self::encode($nonce . $ciphertext);
    }

    /**
     * Decrypts a value, trying retired keys so a rotation does not orphan data.
     *
     * @throws DecryptionFailed on any failure, without saying which
     */
    public function decrypt(string $token, string $context = ''): string
    {
        return $this->tryDecrypt($token, $context) ?? throw DecryptionFailed::create();
    }

    /**
     * The same, returning null instead of throwing.
     *
     * For the cases where a bad value is expected rather than exceptional — a
     * cookie from a previous deployment, say.
     */
    public function tryDecrypt(string $token, string $context = ''): ?string
    {
        $prefix = self::VERSION . '.';

        if (!str_starts_with($token, $prefix)) {
            return null;
        }

        $raw = self::decode(substr($token, strlen($prefix)));

        if ($raw === null) {
            return null;
        }

        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        // Anything shorter cannot hold a nonce and a tag, let alone a message.
        if (strlen($raw) < $nonceLength + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            return null;
        }

        $nonce = substr($raw, 0, $nonceLength);
        $ciphertext = substr($raw, $nonceLength);

        foreach ([$this->key, ...$this->retiredKeys] as $key) {
            try {
                $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    $ciphertext,
                    $context,
                    $nonce,
                    $key->bytes(),
                );
            } catch (Throwable) {
                // A malformed input can make the primitive throw rather than
                // return false; both mean the same thing here.
                continue;
            }

            if ($plaintext !== false) {
                return $plaintext;
            }
        }

        return null;
    }

    /**
     * Whether a string looks like a token this class produced.
     *
     * A cheap format check, not a validity check — it says nothing about
     * whether the value will decrypt.
     */
    public static function looksEncrypted(string $value): bool
    {
        return str_starts_with($value, self::VERSION . '.');
    }

    /**
     * base64url without padding: safe in a URL, a cookie or a header, with no
     * characters that need escaping again later.
     */
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
