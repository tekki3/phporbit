<?php

declare(strict_types=1);

namespace PhpOrbit\Auth;

use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use SensitiveParameter;

/**
 * Request-scoped coordination: no primary method can skip an enrolled factor.
 */
final class LoginFlow
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly UserProvider $users,
        private readonly Session $session,
        private readonly Mfa $mfa,
        private readonly SecurityState $state,
        private readonly LoginThrottle $throttle,
    ) {
    }

    public function begin(Identity $user): void
    {
        $this->clearPending();
        if (!$this->mfa->enabled($user->authIdentifier())) {
            $this->auth->login($user);

            return;
        }

        $this->session->remove(Authenticator::SESSION_KEY);
        $this->session->regenerate();
        Csrf::rotate($this->session);
        $this->session->set('_mfa_user', $user->authIdentifier());
        $this->session->set('_mfa_expires', time() + 300);
        $this->session->set('_mfa_stamp', $this->stamp($user));
    }

    public function pending(): bool
    {
        return $this->pendingUser() !== null;
    }

    public function complete(#[SensitiveParameter] string $code): bool
    {
        $user = $this->pendingUser();
        if ($user === null) {
            return false;
        }
        $key = 'mfa:' . $user->authIdentifier();
        if ($this->throttle->tooManyAttempts($key)) {
            return false;
        }
        // Count before verification, including parallel and successful requests.
        $this->throttle->record($key);
        if (!$this->mfa->verify($user->authIdentifier(), $code)) {
            return false;
        }

        $this->clearPending();
        $this->auth->login($user);

        return true;
    }

    private function pendingUser(): ?Identity
    {
        $id = $this->session->get('_mfa_user');
        if ($id === null || ($this->session->getInt('_mfa_expires') ?? 0) <= time()) {
            $this->clearPending();

            return null;
        }
        $user = $this->users->findByIdentifier($id);
        if ($user === null || !hash_equals($this->stamp($user), $this->session->get('_mfa_stamp') ?? '')) {
            $this->clearPending();

            return null;
        }

        return $user;
    }

    private function stamp(Identity $user): string
    {
        return hash('sha256', $user->passwordHash() . ':' . $this->state->version($user->authIdentifier()));
    }

    private function clearPending(): void
    {
        foreach (['_mfa_user', '_mfa_expires', '_mfa_stamp'] as $key) {
            $this->session->remove($key);
        }
    }
}
