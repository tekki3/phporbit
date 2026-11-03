<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Api;

use Firebase\JWT\JWT;
use Firebase\JWT\Key as JwtKey;
use PhpOrbit\Api\ApiError;
use PhpOrbit\Api\ApiMigration;
use PhpOrbit\Api\Tokens;
use PhpOrbit\Auth\Identity;
use PhpOrbit\Auth\SecurityMigration;
use PhpOrbit\Auth\SecurityState;
use PhpOrbit\Crypto\Key;
use PhpOrbit\Database\Connection;
use PhpOrbit\Tests\Support\ArrayUserProvider;
use PHPUnit\Framework\TestCase;

final class TokensTest extends TestCase
{
    private Connection $database;
    private ArrayUserProvider $users;
    private Identity $user;
    private SecurityState $state;
    private Key $key;
    private Tokens $tokens;

    protected function setUp(): void
    {
        $this->database = Connection::sqlite(':memory:');
        (new SecurityMigration())->up($this->database);
        (new ApiMigration())->up($this->database);
        $this->users = new ArrayUserProvider();
        $this->user = $this->users->add('1', 'ada@example.test', 'stored-hash');
        $this->state = new SecurityState($this->database);
        $this->key = Key::generate();
        $this->tokens = new Tokens($this->database, $this->users, $this->state, $this->key, 'issuer', 'audience');
    }

    public function test_jwt_has_fixed_algorithm_standard_claims_and_a_separate_signing_key(): void
    {
        $token = $this->tokens->jwt($this->user, ['profile:read']);
        $claims = JWT::decode($token->value, new JwtKey($this->key->derive('api-jwt-hs256-v1')->bytes(), 'HS256'));
        self::assertSame('issuer', $claims->iss);
        self::assertSame('audience', $claims->aud);
        self::assertSame('1', $claims->sub);
        self::assertSame($token->id, $claims->jti);
        self::assertIsInt($claims->exp);
        self::assertIsInt($claims->iat);
        self::assertSame(900, $claims->exp - $claims->iat);
        self::assertSame('profile:read', $claims->scope);
        $principal = $this->tokens->authenticate($token->value);
        self::assertSame('1', $principal->user->authIdentifier());
        self::assertTrue($principal->permits('profile:read'));
        self::assertFalse($principal->permits('admin'));
        self::assertStringNotContainsString($token->value, print_r($token, true));
    }

    public function test_personal_tokens_are_hashed_named_scoped_and_individually_revocable(): void
    {
        $first = $this->tokens->personal($this->user, 'CLI', ['profile:read']);
        $second = $this->tokens->personal($this->user, 'Build');
        self::assertMatchesRegularExpression('/^orbit_pat_[a-f0-9]{32}_[a-f0-9]{64}$/D', $first->value);
        self::assertSame($first->id, $this->tokens->authenticate($first->value)->tokenId);
        $rows = $this->database->query('api_tokens')->get();
        self::assertSame(hash('sha256', $first->value), $rows[0]['token_hash']);
        self::assertStringNotContainsString($first->value, json_encode($rows, JSON_THROW_ON_ERROR));
        $this->tokens->revoke('some-other-user', $first->id);
        self::assertSame($first->id, $this->tokens->authenticate($first->value)->tokenId);
        $this->tokens->revoke('1', $first->id);
        self::assertSame($second->id, $this->tokens->authenticate($second->value)->tokenId);
        $this->expectException(ApiError::class);
        $this->tokens->authenticate($first->value);
    }

    public function test_account_revocation_invalidates_both_token_types(): void
    {
        $jwt = $this->tokens->jwt($this->user);
        $personal = $this->tokens->personal($this->user, 'CLI');
        $this->state->revoke('1');
        foreach ([$jwt->value, $personal->value] as $value) {
            $this->assertInvalid($value);
        }
    }

    public function test_password_changes_and_expiration_are_enforced(): void
    {
        $jwt = $this->tokens->jwt($this->user);
        $this->users->updatePasswordHash($this->user, 'new-hash');
        $this->assertInvalid($jwt->value);
        $current = $this->users->findByIdentifier('1');
        self::assertNotNull($current);
        $personal = $this->tokens->personal($current, 'CLI');
        $this->database->query('api_tokens')->where('token_id', '=', $personal->id)->update(['expires_at' => time()]);
        $this->assertInvalid($personal->value);
    }

    public function test_removed_users_and_revoked_jwts_are_rejected(): void
    {
        $jwt = $this->tokens->jwt($this->user);
        $this->tokens->revoke('1', $jwt->id);
        $this->assertInvalid($jwt->value);
        $personal = $this->tokens->personal($this->user, 'CLI');
        $this->users->remove('1');
        $this->assertInvalid($personal->value);
    }

    public function test_tampering_wrong_issuer_audience_and_key_are_rejected(): void
    {
        $jwt = $this->tokens->jwt($this->user);
        $this->assertInvalid($jwt->value . 'x');
        $this->assertInvalid('orbit_pat_' . str_repeat('0', 32) . '_' . str_repeat('a', 64));
        foreach ([
            new Tokens($this->database, $this->users, $this->state, $this->key, 'wrong', 'audience'),
            new Tokens($this->database, $this->users, $this->state, $this->key, 'issuer', 'wrong'),
            new Tokens($this->database, $this->users, $this->state, Key::generate(), 'issuer', 'audience'),
        ] as $verifier) {
            try {
                $verifier->authenticate($jwt->value);
                self::fail('Mismatched JWT verifier must reject the token.');
            } catch (ApiError $error) {
                self::assertSame(401, $error->status->value);
            }
        }
    }

    public function test_stale_mfa_security_snapshot_cannot_issue_a_token(): void
    {
        $version = $this->state->version('1');
        $this->state->revoke('1');
        $this->expectException(ApiError::class);
        $this->tokens->jwt($this->user, securityVersion: $version);
    }

    public function test_token_lifetime_and_scopes_are_validated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tokens->jwt($this->user, ['*'], 86400);
    }

    private function assertInvalid(string $value): void
    {
        try {
            $this->tokens->authenticate($value);
            self::fail('Invalid token was accepted.');
        } catch (ApiError $error) {
            self::assertSame(401, $error->status->value);
        }
    }
}
