<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Auth;

use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\EmailTokens;
use PhpOrbit\Auth\Identity;
use PhpOrbit\Auth\LoginFlow;
use PhpOrbit\Auth\LoginThrottle;
use PhpOrbit\Auth\Mfa;
use PhpOrbit\Auth\PasswordHasher;
use PhpOrbit\Auth\PasswordReset;
use PhpOrbit\Auth\SecurityState;
use PhpOrbit\Auth\Totp;
use PhpOrbit\Crypto\Encrypter;
use PhpOrbit\Crypto\Key;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migration;
use PhpOrbit\Session\Session;
use PhpOrbit\Tests\Support\ArrayUserProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AdvancedAuthenticationTest extends TestCase
{
    private Connection $database;
    private ArrayUserProvider $users;
    private SecurityState $state;
    private Mfa $mfa;
    private EmailTokens $tokens;
    private PasswordHasher $hasher;
    private Identity $user;

    protected function setUp(): void
    {
        $this->database = Connection::sqlite(':memory:');
        foreach (['0003_create_auth_attempts', '0005_create_auth_security'] as $name) {
            $migration = require dirname(__DIR__, 3) . '/database/migrations/' . $name . '.php';
            self::assertInstanceOf(Migration::class, $migration);
            $migration->up($this->database);
        }
        $this->hasher = new PasswordHasher();
        $this->users = new ArrayUserProvider();
        $this->user = $this->users->add('1', 'ada@example.test', $this->hasher->hash('correct-horse-battery'));
        $this->state = new SecurityState($this->database);
        $this->mfa = new Mfa($this->database, $this->state, new Encrypter(Key::generate()));
        $this->tokens = new EmailTokens($this->database, $this->users, $this->state);
    }

    /** @return iterable<string, array{int, string}> */
    public static function vectors(): iterable
    {
        yield '59' => [59, '287082'];
        yield '1111111109' => [1111111109, '081804'];
        yield '1111111111' => [1111111111, '050471'];
        yield '1234567890' => [1234567890, '005924'];
        yield '2000000000' => [2000000000, '279037'];
        yield '20000000000' => [20000000000, '353130'];
    }

    #[DataProvider('vectors')]
    public function test_totp_matches_rfc_6238_truncated_to_six_digits(int $time, string $code): void
    {
        self::assertSame($code, Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $time));
    }

    public function test_totp_has_bounded_skew_and_strict_input(): void
    {
        $secret = Totp::secret();
        self::assertSame(32, strlen($secret));
        self::assertSame(101, Totp::match($secret, Totp::code($secret, 3030), 3000));
        self::assertNull(Totp::match($secret, Totp::code($secret, 3090), 3000));
        self::assertNull(Totp::match($secret, '1234567', 3000));
        self::assertNull(Totp::match($secret, ' 123456', 3000));
        self::assertStringContainsString('secret=' . $secret, Totp::uri($secret, 'a@example.test'));
    }

    public function test_enrollment_encrypts_secret_hashes_codes_and_prevents_replay(): void
    {
        $secret = Totp::secret();
        $code = Totp::code($secret, time());
        $codes = $this->mfa->enable('1', $secret, $code);
        self::assertCount(10, $codes);
        self::assertTrue($this->mfa->enabled('1'));
        self::assertNotSame($secret, $this->state->row('1')['totp_secret'] ?? null);
        self::assertFalse($this->mfa->verify('1', $code));
        self::assertTrue($this->mfa->verify('1', $codes[0]));
        self::assertFalse($this->mfa->verify('1', $codes[0]));
        self::assertFalse($this->mfa->verify('other', $codes[1]));
        $stored = $this->database->query('auth_recovery_codes')->get();
        self::assertCount(9, $stored);
        foreach ($stored as $row) {
            self::assertNotContains($row['code_hash'], $codes);
        }
        self::assertTrue($this->mfa->verify('1', Totp::code($secret, time() + 30)));
        self::assertFalse($this->mfa->verify('1', Totp::code($secret, time() + 30)));
    }

    public function test_invalid_enrollment_does_not_change_account(): void
    {
        try {
            $this->mfa->enable('1', Totp::secret(), 'bad');
            self::fail('Enrollment must verify a real code.');
        } catch (AuthenticationFailed) {
            self::assertFalse($this->mfa->enabled('1'));
            self::assertSame('initial', $this->state->version('1'));
        }
    }

    public function test_disable_requires_a_factor_and_revokes_all_remaining_codes(): void
    {
        $secret = Totp::secret();
        $codes = $this->mfa->enable('1', $secret, Totp::code($secret, time()));
        $before = $this->state->version('1');
        try {
            $this->mfa->disable('1', 'bad');
            self::fail('Invalid codes cannot disable MFA.');
        } catch (AuthenticationFailed) {
            self::assertTrue($this->mfa->enabled('1'));
        }
        $this->mfa->disable('1', $codes[0]);
        self::assertFalse($this->mfa->enabled('1'));
        self::assertNotSame($before, $this->state->version('1'));
        self::assertFalse($this->mfa->verify('1', $codes[1]));
        self::assertSame(0, $this->database->query('auth_recovery_codes')->count());
    }

    public function test_tokens_are_hashed_purpose_bound_and_single_use(): void
    {
        $token = $this->tokens->issue($this->user, 'magic-login');
        self::assertSame(64, strlen($token));
        self::assertSame(hash('sha256', $token), $this->database->query('auth_email_tokens')->value('token_hash'));
        try {
            $this->tokens->consume($token, 'password-reset', static function (): void {
                self::fail('The purpose cannot be substituted.');
            });
        } catch (AuthenticationFailed) {
            self::assertSame(1, $this->database->query('auth_email_tokens')->count());
        }
        $this->tokens->consume($token, 'magic-login', static function (Identity $user): void {
            self::assertSame('1', $user->authIdentifier());
        });
        $this->expectException(AuthenticationFailed::class);
        $this->tokens->consume($token, 'magic-login', static function (): void {
            self::fail('A token cannot be replayed.');
        });
    }

    public function test_expired_tokens_are_refused(): void
    {
        $token = $this->tokens->issue($this->user, 'magic-login');
        $this->database->query('auth_email_tokens')->affectingEveryRow()->update(['expires_at' => time()]);
        $this->expectException(AuthenticationFailed::class);
        $this->tokens->consume($token, 'magic-login', static function (): void {
            self::fail('Expired token accepted.');
        });
    }

    public function test_token_and_action_roll_back_together_on_failure(): void
    {
        $token = $this->tokens->issue($this->user, 'magic-login');
        try {
            $this->tokens->consume($token, 'magic-login', static function (): void {
                throw new RuntimeException('action failed');
            });
            self::fail('The error must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('action failed', $exception->getMessage());
        }
        self::assertSame(1, $this->database->query('auth_email_tokens')->count());
    }

    public function test_expired_tokens_can_be_purged_without_deleting_live_tokens(): void
    {
        $expired = $this->tokens->issue($this->user, 'magic-login');
        $live = $this->tokens->issue($this->user, 'password-reset');
        $this->database->query('auth_email_tokens')->where('token_hash', '=', hash('sha256', $expired))->update(['expires_at' => time()]);
        self::assertSame(1, $this->tokens->purge());
        self::assertSame(hash('sha256', $live), $this->database->query('auth_email_tokens')->value('token_hash'));
    }

    public function test_short_reset_password_does_not_consume_the_link(): void
    {
        $token = $this->tokens->issue($this->user, 'password-reset');
        try {
            (new PasswordReset($this->tokens, $this->users, $this->hasher, $this->state))->reset($token, 'short');
            self::fail('Short reset passwords must be refused.');
        } catch (\InvalidArgumentException) {
            self::assertSame(1, $this->database->query('auth_email_tokens')->count());
        }
    }

    public function test_stamp_configuration_refuses_unstamped_and_tampered_sessions(): void
    {
        $session = Session::started();
        $session->set(Authenticator::SESSION_KEY, '1');
        self::assertFalse($this->auth($session)->check());
        $this->auth($session)->login($this->user);
        $session->set('_auth_stamp', 'tampered');
        self::assertFalse($this->auth($session)->check());
    }

    public function test_password_reset_revokes_links_and_sessions_but_preserves_mfa(): void
    {
        $session = Session::started();
        $this->auth($session)->login($this->user);
        $secret = Totp::secret();
        $this->mfa->enable('1', $secret, Totp::code($secret, time()));
        $token = $this->tokens->issue($this->user, 'password-reset');
        $this->tokens->issue($this->user, 'magic-login');
        (new PasswordReset($this->tokens, $this->users, $this->hasher, $this->state))->reset($token, 'new-correct-password');
        self::assertTrue($this->hasher->verify('new-correct-password', $this->users->hashFor('1')));
        self::assertSame(0, $this->database->query('auth_email_tokens')->count());
        self::assertTrue($this->mfa->enabled('1'));
        self::assertFalse($this->auth($session)->check());
    }

    public function test_security_changes_invalidate_outstanding_email_links(): void
    {
        $token = $this->tokens->issue($this->user, 'magic-login');
        $this->state->revoke('1');
        $this->expectException(AuthenticationFailed::class);
        $this->tokens->consume($token, 'magic-login', static function (): void {
            self::fail('Revoked token accepted.');
        });
    }

    public function test_primary_factor_never_creates_full_session_before_mfa(): void
    {
        $secret = Totp::secret();
        $codes = $this->mfa->enable('1', $secret, Totp::code($secret, time()));
        $session = Session::started();
        $auth = $this->auth($session);
        self::assertNotNull($auth->validateCredentials('ada@example.test', 'correct-horse-battery'));
        self::assertFalse($auth->check());
        $flow = $this->flow($session);
        $flow->begin($this->user);
        self::assertTrue($flow->pending());
        self::assertNull($session->get(Authenticator::SESSION_KEY));
        self::assertFalse($flow->complete('bad'));
        self::assertTrue($flow->complete($codes[0]));
        self::assertTrue($this->auth($session)->check());
        self::assertFalse($flow->pending());
        self::assertFalse($flow->complete($codes[1]));
    }

    public function test_expiration_revocation_and_global_throttle_protect_pending_challenges(): void
    {
        $secret = Totp::secret();
        $codes = $this->mfa->enable('1', $secret, Totp::code($secret, time()));
        $session = Session::started();
        $flow = $this->flow($session);
        $flow->begin($this->user);
        $session->set('_mfa_expires', time());
        self::assertFalse($flow->complete($codes[0]));
        $flow->begin($this->user);
        $this->state->revoke('1');
        self::assertFalse($flow->complete($codes[0]));
        for ($index = 0; $index < 5; $index++) {
            $newSession = Session::started();
            $attempt = $this->flow($newSession);
            $attempt->begin($this->user);
            self::assertFalse($attempt->complete('bad'));
        }
        $flow->begin($this->user);
        self::assertFalse($flow->complete($codes[0]), 'A new primary-factor session must not reset the MFA throttle.');
    }

    private function auth(Session $session): Authenticator
    {
        return new Authenticator($session, $this->users, $this->hasher,
            fn (Identity $user): string => hash('sha256', $user->passwordHash() . ':' . $this->state->version($user->authIdentifier())));
    }

    private function flow(Session $session): LoginFlow
    {
        return new LoginFlow($this->auth($session), $this->users, $session, $this->mfa, $this->state, new LoginThrottle($this->database));
    }
}
