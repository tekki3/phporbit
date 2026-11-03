<?php

declare(strict_types=1);

namespace PhpOrbit\Auth;

use InvalidArgumentException;
use SensitiveParameter;

final class PasswordReset
{
    public function __construct(
        private readonly EmailTokens $tokens,
        private readonly UserProvider $users,
        private readonly PasswordHasher $hasher,
        private readonly SecurityState $state,
    ) {
    }

    public function reset(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 72) {
            throw new InvalidArgumentException('Choose a password between 12 and 72 bytes.');
        }
        $hash = $this->hasher->hash($password);
        $this->tokens->consume($token, 'password-reset', function (Identity $user) use ($hash): void {
            $this->users->updatePasswordHash($user, $hash);
            $this->state->revoke($user->authIdentifier());
            $this->tokens->revoke($user->authIdentifier());
        });
    }
}
