<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Auth;

use InvalidArgumentException;
use PhpOrbit\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new PasswordHasher();
    }

    public function test_it_verifies_the_right_password(): void
    {
        $hash = $this->hasher->hash('correct-horse');

        self::assertTrue($this->hasher->verify('correct-horse', $hash));
        self::assertFalse($this->hasher->verify('wrong', $hash));
    }

    /**
     * A per-hash salt is what stops one rainbow table from covering every user
     * who chose the same password.
     */
    public function test_the_same_password_hashes_differently_each_time(): void
    {
        self::assertNotSame($this->hasher->hash('same'), $this->hasher->hash('same'));
    }

    public function test_the_plaintext_never_appears_in_the_hash(): void
    {
        self::assertStringNotContainsString('correct-horse', $this->hasher->hash('correct-horse'));
    }

    public function test_it_uses_a_slow_algorithm(): void
    {
        $hash = $this->hasher->hash('x');

        self::assertMatchesRegularExpression('/^\$(argon2id|2y)\$/', $hash);
    }

    public function test_an_empty_password_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->hasher->hash('');
    }

    /**
     * bcrypt ignores everything past 72 bytes, which would make two different
     * long passwords interchangeable. Rejecting beats truncating.
     */
    public function test_an_over_long_password_is_refused_rather_than_truncated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/truncated/');

        $this->hasher->hash(str_repeat('a', PasswordHasher::MAX_LENGTH + 1));
    }

    public function test_verification_rejects_empty_input(): void
    {
        self::assertFalse($this->hasher->verify('', $this->hasher->hash('x')));
        self::assertFalse($this->hasher->verify('x', ''));
    }

    public function test_a_current_hash_does_not_need_rehashing(): void
    {
        self::assertFalse($this->hasher->needsRehash($this->hasher->hash('x')));
    }

    public function test_a_weaker_hash_is_flagged_for_rehashing(): void
    {
        self::assertTrue($this->hasher->needsRehash(password_hash('x', PASSWORD_BCRYPT, ['cost' => 4])));
    }

    /**
     * The decoy verification exists so that "no such user" costs the same as
     * "wrong password"; it must always fail.
     */
    public function test_the_decoy_verification_always_fails(): void
    {
        self::assertFalse($this->hasher->dummyVerify('anything'));
        self::assertFalse($this->hasher->dummyVerify(''));
    }
}
