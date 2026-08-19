<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Crypto;

use PhpOrbit\Crypto\DecryptionFailed;
use PhpOrbit\Crypto\Encrypter;
use PhpOrbit\Crypto\Key;
use PhpOrbit\Crypto\Signer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    public function test_it_round_trips(): void
    {
        $signer = new Signer(Key::generate());

        foreach (['', 'user:42', 'a value with . dots . in it', "\0binary\xff"] as $value) {
            self::assertSame($value, $signer->verify($signer->sign($value)));
        }
    }

    /**
     * Signing protects a value it does not hide — that is the whole point, and
     * why encrypting instead would be the wrong tool.
     */
    public function test_the_value_is_readable_but_not_changeable(): void
    {
        $signer = new Signer(Key::generate());
        $signed = $signer->sign('unsubscribe:ada@example.test');

        $payload = base64_decode(strtr(explode('.', $signed)[0], '-_', '+/'), true);

        self::assertIsString($payload);
        self::assertStringContainsString('ada@example.test', $payload);
    }

    #[DataProvider('forgeries')]
    public function test_a_forged_signature_is_rejected(callable $forge): void
    {
        $signer = new Signer(Key::generate());

        /** @var string $forged */
        $forged = $forge($signer->sign('user:42'));

        self::assertNull($signer->verify($forged));
    }

    /**
     * @return iterable<string, array{callable}>
     */
    public static function forgeries(): iterable
    {
        // Re-signing a changed payload with no signature at all.
        yield 'changed payload' => [static function (string $signed): string {
            [, $mac] = explode('.', $signed);

            return rtrim(strtr(base64_encode(':user:99'), '+/', '-_'), '=') . '.' . $mac;
        }];

        yield 'changed signature' => [static function (string $signed): string {
            [$payload] = explode('.', $signed);

            return $payload . '.' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }];

        yield 'no signature' => [static fn (string $s): string => explode('.', $s)[0]];
        yield 'empty' => [static fn (): string => ''];
        yield 'too many parts' => [static fn (string $s): string => $s . '.extra'];
    }

    public function test_a_different_key_does_not_verify(): void
    {
        $signed = (new Signer(Key::generate()))->sign('user:42');

        self::assertNull((new Signer(Key::generate()))->verify($signed));
    }

    // --- expiry ---------------------------------------------------------------

    public function test_a_signature_can_expire(): void
    {
        $signer = new Signer(Key::generate());

        self::assertSame('reset:42', $signer->verify($signer->sign('reset:42', ttlSeconds: 60)));
        self::assertNull($signer->verify($signer->sign('reset:42', ttlSeconds: -1)));
    }

    public function test_without_a_ttl_a_signature_does_not_expire(): void
    {
        $signer = new Signer(Key::generate());

        self::assertSame('preference:dark', $signer->verify($signer->sign('preference:dark')));
    }

    /**
     * The expiry is inside the signed payload, so moving the deadline requires
     * the key.
     */
    public function test_the_expiry_cannot_be_extended_without_the_key(): void
    {
        $signer = new Signer(Key::generate());
        $signed = $signer->sign('reset:42', ttlSeconds: -1);

        [, $mac] = explode('.', $signed);
        $extended = rtrim(strtr(base64_encode((time() + 3600) . ':reset:42'), '+/', '-_'), '=') . '.' . $mac;

        self::assertNull($signer->verify($extended));
    }

    // --- rotation and separation ----------------------------------------------

    public function test_a_retired_key_still_verifies_old_signatures(): void
    {
        $old = Key::generate();
        $new = Key::generate();

        $signed = (new Signer($old))->sign('issued:before');

        self::assertSame('issued:before', (new Signer($new, [$old]))->verify($signed));
    }

    /**
     * The signer and the encrypter share one configured secret but never the
     * same derived key, so a weakness in one cannot compromise the other.
     */
    public function test_signing_and_encryption_do_not_share_key_material(): void
    {
        $key = Key::generate();

        $signed = (new Signer($key))->sign('value');
        $encrypted = (new Encrypter($key))->encrypt('value');

        self::assertNull((new Encrypter($key))->tryDecrypt($signed));
        self::assertNull((new Signer($key))->verify($encrypted));
    }

    public function test_verify_or_fail_throws(): void
    {
        $this->expectException(DecryptionFailed::class);

        (new Signer(Key::generate()))->verifyOrFail('nonsense');
    }
}
