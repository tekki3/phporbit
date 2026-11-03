<?php

declare(strict_types=1);

namespace PhpOrbit\Auth;

use Closure;
use InvalidArgumentException;
use PhpOrbit\Database\Connection;
use SensitiveParameter;

/**
 * Tokens are stored hashed, expire, and are claimed atomically with the action.
 * A token is additionally bound to the password hash and security version.
 */
final class EmailTokens
{
    public function __construct(
        private readonly Connection $database,
        private readonly UserProvider $users,
        private readonly SecurityState $state,
    ) {
    }

    public function issue(Identity $user, string $purpose, int $lifetimeSeconds = 900): string
    {
        $this->purpose($purpose);
        if ($lifetimeSeconds < 1 || $lifetimeSeconds > 3600) {
            throw new InvalidArgumentException('Email token lifetimes must be between 1 and 3600 seconds.');
        }
        $token = bin2hex(random_bytes(32));
        $this->database->query('auth_email_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'user_identifier' => $user->authIdentifier(),
            'purpose' => $purpose,
            'credential_stamp' => $this->stamp($user),
            'expires_at' => time() + $lifetimeSeconds,
        ]);

        return $token;
    }

    /** @param Closure(Identity): void $action */
    public function consume(#[SensitiveParameter] string $token, string $purpose, Closure $action): void
    {
        $this->purpose($purpose);
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new AuthenticationFailed('This link is invalid, expired or has already been used.');
        }

        $this->database->transaction(function () use ($token, $purpose, $action): void {
            $hash = hash('sha256', $token);
            $query = $this->database->query('auth_email_tokens')->where('token_hash', '=', $hash)->where('purpose', '=', $purpose);
            $row = $query->first();
            if ($row === null || (int) $row['expires_at'] <= time()) {
                throw new AuthenticationFailed('This link is invalid, expired or has already been used.');
            }
            $user = $this->users->findByIdentifier((string) $row['user_identifier']);
            if ($user === null || !hash_equals((string) $row['credential_stamp'], $this->stamp($user))
                || $query->delete() !== 1) {
                throw new AuthenticationFailed('This link is invalid, expired or has already been used.');
            }
            $action($user);
        });
    }

    public function revoke(string $user): void
    {
        $this->database->query('auth_email_tokens')->where('user_identifier', '=', $user)->delete();
    }

    public function purge(): int
    {
        return $this->database->query('auth_email_tokens')->where('expires_at', '<=', time())->delete();
    }

    private function stamp(Identity $user): string
    {
        return hash('sha256', $user->passwordHash() . ':' . $this->state->version($user->authIdentifier()));
    }

    private function purpose(string $purpose): void
    {
        if (!in_array($purpose, ['password-reset', 'magic-login'], true)) {
            throw new InvalidArgumentException('Unknown email token purpose.');
        }
    }
}
