<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Auth;

use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\PasswordHasher;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\Tests\Support\ArrayUserProvider;
use PHPUnit\Framework\TestCase;

final class AuthenticatorTest extends TestCase
{
    private PasswordHasher $hasher;

    private ArrayUserProvider $users;

    protected function setUp(): void
    {
        $this->hasher = new PasswordHasher();
        $this->users = new ArrayUserProvider();
        $this->users->add('1', 'ada@example.test', $this->hasher->hash('correct-horse'));
    }

    public function test_a_fresh_session_is_a_guest(): void
    {
        $auth = $this->authenticator(Session::started());

        self::assertTrue($auth->guest());
        self::assertFalse($auth->check());
        self::assertNull($auth->user());
    }

    public function test_correct_credentials_sign_the_user_in(): void
    {
        $auth = $this->authenticator(Session::started());

        self::assertTrue($auth->attempt('ada@example.test', 'correct-horse'));
        self::assertTrue($auth->check());
        self::assertSame('1', $auth->user()?->authIdentifier());
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $auth = $this->authenticator(Session::started());

        self::assertFalse($auth->attempt('ada@example.test', 'wrong'));
        self::assertFalse($auth->check());
    }

    public function test_an_unknown_address_is_refused(): void
    {
        $auth = $this->authenticator(Session::started());

        self::assertFalse($auth->attempt('nobody@example.test', 'correct-horse'));
    }

    /**
     * Session fixation: an attacker who fixes the victim's session id before
     * they sign in must not hold a valid one afterwards.
     */
    public function test_logging_in_regenerates_the_session_id(): void
    {
        $session = Session::started();
        $original = $session->id();

        $this->authenticator($session)->attempt('ada@example.test', 'correct-horse');

        self::assertNotSame($original, $session->id());
    }

    /**
     * The CSRF token is a pre-login credential too, so it is replaced as well.
     */
    public function test_logging_in_rotates_the_csrf_token(): void
    {
        $session = Session::started();
        $before = Csrf::token($session);

        $this->authenticator($session)->attempt('ada@example.test', 'correct-horse');

        self::assertNotSame($before, Csrf::token($session));
    }

    public function test_logout_destroys_the_session(): void
    {
        $session = Session::started();
        $auth = $this->authenticator($session);
        $auth->attempt('ada@example.test', 'correct-horse');

        $auth->logout();

        self::assertTrue($auth->guest());
        self::assertTrue($session->isDestroyed());
    }

    /**
     * Only the identifier is stored; the user is re-fetched each request so a
     * deleted account loses access immediately rather than when the session
     * happens to expire.
     */
    public function test_a_deleted_user_is_no_longer_authenticated(): void
    {
        $session = Session::started();
        $this->authenticator($session)->attempt('ada@example.test', 'correct-horse');

        $this->users->remove('1');

        $fresh = $this->authenticator($session);

        self::assertFalse($fresh->check());
        self::assertNull($session->get(Authenticator::SESSION_KEY), 'the stale claim is dropped');
    }

    public function test_the_session_carries_only_the_identifier(): void
    {
        $session = Session::started();
        $this->authenticator($session)->attempt('ada@example.test', 'correct-horse');

        $stored = implode('|', array_map(strval(...), $session->all()));

        self::assertStringNotContainsString('correct-horse', $stored);
        self::assertStringNotContainsString('$argon2', $stored);
        self::assertStringNotContainsString('$2y$', $stored);
    }

    /**
     * A hash produced with outdated parameters is upgraded on the one occasion
     * the plaintext is available.
     */
    public function test_an_outdated_hash_is_upgraded_on_login(): void
    {
        // A deliberately cheap bcrypt hash, which Argon2id settings supersede.
        $weak = password_hash('correct-horse', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->users->add('2', 'grace@example.test', $weak);

        $auth = $this->authenticator(Session::started());

        self::assertTrue($auth->attempt('grace@example.test', 'correct-horse'));
        self::assertNotSame($weak, $this->users->hashFor('2'));
        self::assertTrue($this->hasher->verify('correct-horse', $this->users->hashFor('2')));
    }

    private function authenticator(Session $session): Authenticator
    {
        return new Authenticator($session, $this->users, $this->hasher);
    }
}
