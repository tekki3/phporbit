<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Crypto;

use InvalidArgumentException;
use PhpOrbit\Config\Environment;
use PhpOrbit\Crypto\CryptoFactory;
use PhpOrbit\Crypto\DecryptionFailed;
use PhpOrbit\Crypto\Encrypter;
use PhpOrbit\Crypto\Key;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EncrypterTest extends TestCase
{
    public function test_it_round_trips(): void
    {
        $encrypter = new Encrypter(Key::generate());

        foreach (['', 'hello', str_repeat('x', 100_000), "\0\1\2 binary", 'emoji 🚀 ünïcode'] as $plaintext) {
            self::assertSame($plaintext, $encrypter->decrypt($encrypter->encrypt($plaintext)));
        }
    }

    /**
     * A fresh nonce per message, so the same plaintext never produces the same
     * token — otherwise an observer learns which records are equal without
     * decrypting any of them.
     */
    public function test_the_same_plaintext_encrypts_differently_every_time(): void
    {
        $encrypter = new Encrypter(Key::generate());

        $tokens = [];
        for ($i = 0; $i < 20; $i++) {
            $tokens[] = $encrypter->encrypt('the same value');
        }

        self::assertCount(20, array_unique($tokens));
    }

    public function test_a_token_is_url_and_cookie_safe(): void
    {
        $token = (new Encrypter(Key::generate()))->encrypt(str_repeat("\xff\x00", 64));

        self::assertMatchesRegularExpression('/^v1\.[A-Za-z0-9_-]+$/', $token);
        self::assertSame($token, rawurlencode($token), 'a token should survive a URL unchanged');
    }

    // --- tampering ------------------------------------------------------------

    /**
     * The whole point of authenticated encryption: a modified ciphertext is
     * rejected, not decrypted into something the application then trusts.
     */
    #[DataProvider('tamperings')]
    public function test_tampering_is_detected(callable $tamper): void
    {
        $encrypter = new Encrypter(Key::generate());
        $token = $encrypter->encrypt('transfer 100 to ada');

        /** @var string $tampered */
        $tampered = $tamper($token);

        self::assertNull($encrypter->tryDecrypt($tampered));
    }

    /**
     * @return iterable<string, array{callable}>
     */
    public static function tamperings(): iterable
    {
        yield 'flip a byte' => [static function (string $t): string {
            $position = strlen($t) - 5;
            $t[$position] = $t[$position] === 'A' ? 'B' : 'A';

            return $t;
        }];

        yield 'truncate' => [static fn (string $t): string => substr($t, 0, -4)];
        yield 'append' => [static fn (string $t): string => $t . 'AAAA'];
        yield 'strip the version' => [static fn (string $t): string => substr($t, 3)];
        yield 'wrong version' => [static fn (string $t): string => 'v2.' . substr($t, 3)];
        yield 'not a token at all' => [static fn (): string => 'hello'];
        yield 'empty' => [static fn (): string => ''];
    }

    public function test_a_different_key_cannot_decrypt(): void
    {
        $token = (new Encrypter(Key::generate()))->encrypt('secret');

        self::assertNull((new Encrypter(Key::generate()))->tryDecrypt($token));
    }

    /**
     * One exception and one message whatever went wrong. Distinguishing the
     * causes would tell an attacker which part of a forgery was closest.
     */
    public function test_every_failure_reports_the_same_thing(): void
    {
        $encrypter = new Encrypter(Key::generate());

        $messages = [];

        foreach (['v1.aaaa', 'not-a-token', '', 'v1.' . str_repeat('A', 80)] as $bad) {
            try {
                $encrypter->decrypt($bad);
                self::fail('expected a failure for: ' . $bad);
            } catch (DecryptionFailed $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertCount(1, array_unique($messages));
    }

    // --- context --------------------------------------------------------------

    /**
     * Binding a ciphertext to where it belongs stops an attacker who can write
     * to the database from moving one row's value into another row.
     */
    public function test_a_ciphertext_cannot_be_moved_to_another_context(): void
    {
        $encrypter = new Encrypter(Key::generate());

        $token = $encrypter->encrypt('ada@example.test', 'users.email:42');

        self::assertSame('ada@example.test', $encrypter->decrypt($token, 'users.email:42'));

        // The same field on a different row, and the wrong field entirely.
        self::assertNull($encrypter->tryDecrypt($token, 'users.email:99'));
        self::assertNull($encrypter->tryDecrypt($token, 'users.phone:42'));
        self::assertNull($encrypter->tryDecrypt($token));
    }

    public function test_the_context_is_not_recoverable_from_the_token(): void
    {
        $token = (new Encrypter(Key::generate()))->encrypt('value', 'users.email:42');

        self::assertStringNotContainsString('users.email', base64_decode(strtr(substr($token, 3), '-_', '+/')) ?: '');
    }

    // --- rotation -------------------------------------------------------------

    public function test_a_retired_key_still_decrypts_old_data(): void
    {
        $old = Key::generate();
        $new = Key::generate();

        $token = (new Encrypter($old))->encrypt('written before the rotation');

        $rotated = new Encrypter($new, [$old]);

        self::assertSame('written before the rotation', $rotated->decrypt($token));

        // New values use the new key only, so the old one can eventually go.
        self::assertNull((new Encrypter($old))->tryDecrypt($rotated->encrypt('written after')));
    }

    // --- keys -----------------------------------------------------------------

    public function test_a_key_round_trips_through_configuration(): void
    {
        $key = Key::generate();
        $exported = $key->exportForConfiguration();

        self::assertStringStartsWith('base64:', $exported);

        $token = (new Encrypter(Key::fromString($exported)))->encrypt('hello');

        self::assertSame('hello', (new Encrypter($key))->decrypt($token));
    }

    #[DataProvider('badKeys')]
    public function test_an_unusable_key_is_refused(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Key::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badKeys(): iterable
    {
        yield 'no prefix' => [base64_encode(random_bytes(32))];
        yield 'too short' => ['base64:' . base64_encode(random_bytes(16))];
        yield 'too long' => ['base64:' . base64_encode(random_bytes(64))];
        yield 'not base64' => ['base64:!!!not base64!!!'];
        yield 'empty' => ['base64:'];
    }

    /**
     * Keys escape through logs, dumps and serialised state far more often than
     * through cryptanalysis.
     */
    public function test_a_key_cannot_be_printed_or_serialised(): void
    {
        $key = Key::generate();
        $secret = base64_encode($key->bytes());

        self::assertStringNotContainsString($secret, print_r($key, true));
        self::assertStringNotContainsString($secret, var_export($key, true));
        self::assertSame('<redacted>', (string) $key);

        $this->expectException(InvalidArgumentException::class);
        serialize($key);
    }

    /**
     * One secret to manage, but the two purposes never share key material.
     */
    public function test_derived_keys_differ_by_purpose(): void
    {
        $key = Key::generate();

        self::assertNotSame($key->derive('a')->bytes(), $key->derive('b')->bytes());
        self::assertSame($key->derive('a')->bytes(), $key->derive('a')->bytes());
        self::assertNotSame($key->bytes(), $key->derive('a')->bytes());
    }

    // --- configuration --------------------------------------------------------

    public function test_a_blank_app_key_fails_at_boot(): void
    {
        $this->expectExceptionMessageMatches('/APP_KEY/');

        CryptoFactory::encrypterFromEnvironment(Environment::fromArray(['APP_KEY' => '']));
    }

    public function test_a_malformed_app_key_names_the_fix(): void
    {
        $this->expectExceptionMessageMatches('/key:generate/');

        CryptoFactory::encrypterFromEnvironment(Environment::fromArray(['APP_KEY' => 'hunter2']));
    }

    public function test_previous_keys_are_read_as_a_list(): void
    {
        $current = Key::generate();
        $retired = Key::generate();

        $token = (new Encrypter($retired))->encrypt('old data');

        $encrypter = CryptoFactory::encrypterFromEnvironment(Environment::fromArray([
            'APP_KEY' => $current->exportForConfiguration(),
            'APP_PREVIOUS_KEYS' => $retired->exportForConfiguration(),
        ]));

        self::assertSame('old data', $encrypter->decrypt($token));
    }
}
