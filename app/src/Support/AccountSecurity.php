<?php

declare(strict_types=1);

namespace App\Support;

use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\Identity;
use PhpOrbit\Auth\LoginThrottle;
use PhpOrbit\Auth\Mfa;
use PhpOrbit\Auth\PasswordHasher;
use PhpOrbit\Auth\SecurityState;
use PhpOrbit\Http\ServerRequest;

final class AccountSecurity
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly PasswordHasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly Mfa $mfa,
        private readonly SecurityState $state,
    ) {
    }

    public function confirm(ServerRequest $request, bool $factor = true): Identity
    {
        $user = $this->auth->user() ?? throw new AuthenticationFailed('Sign in before managing account security.');
        $key = 'reauth:' . $user->authIdentifier();
        if ($this->throttle->tooManyAttempts($key)) {
            throw new AuthenticationFailed('Too many security attempts. Try again in fifteen minutes.');
        }
        $this->throttle->record($key);
        if (!$this->hasher->verify($request->form('password') ?? '', $user->passwordHash())
            || ($factor && $this->mfa->enabled($user->authIdentifier()) && !$this->mfa->verify($user->authIdentifier(), $request->form('code') ?? ''))) {
            throw new AuthenticationFailed('Confirm your password and, if enabled, an unused authenticator or recovery code.');
        }

        return $user;
    }

    public function stamp(Identity $user): string
    {
        return hash('sha256', $user->passwordHash() . ':' . $this->state->version($user->authIdentifier()));
    }
}
