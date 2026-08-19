<?php

declare(strict_types=1);

namespace PhpOrbit\Auth;

use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;

/**
 * Who is logged in, for this request.
 *
 * Request-scoped: it holds the resolved user, which must never be shared
 * between requests in a worker.
 *
 * The session stores only the identifier. The user is re-fetched from the
 * provider on each request so that a deactivated or deleted account loses
 * access immediately, rather than as far in the future as their session
 * happens to last.
 */
final class Authenticator
{
    public const SESSION_KEY = '_auth_id';

    private ?Identity $user = null;

    private bool $resolved = false;

    public function __construct(
        private readonly Session $session,
        private readonly UserProvider $users,
        private readonly PasswordHasher $hasher,
    ) {
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function user(): ?Identity
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        $identifier = $this->session->get(self::SESSION_KEY);

        if ($identifier === null || $identifier === '') {
            return null;
        }

        $this->user = $this->users->findByIdentifier($identifier);

        // The session named someone who no longer exists; drop the claim so
        // later requests stop paying for the lookup.
        if ($this->user === null) {
            $this->session->remove(self::SESSION_KEY);
        }

        return $this->user;
    }

    /**
     * Verifies credentials and logs the user in on success.
     *
     * A failed lookup still runs a hash verification, so the response time
     * does not reveal whether the address is registered.
     */
    public function attempt(string $email, string $password): bool
    {
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            $this->hasher->dummyVerify($password);

            return false;
        }

        if (!$this->hasher->verify($password, $user->passwordHash())) {
            return false;
        }

        // The stored hash predates the current cost settings; now is the only
        // moment the plaintext is available to upgrade it.
        if ($this->hasher->needsRehash($user->passwordHash())) {
            $this->users->updatePasswordHash($user, $this->hasher->hash($password));
        }

        $this->login($user);

        return true;
    }

    /**
     * Marks a user as logged in.
     *
     * Both the session id and the CSRF token are replaced. An attacker who
     * fixed either value before the victim signed in would otherwise still
     * hold a valid one afterwards — the point at which privilege changes is
     * exactly when old credentials must stop working.
     */
    public function login(Identity $user): void
    {
        $this->session->regenerate();
        Csrf::rotate($this->session);

        $this->session->set(self::SESSION_KEY, $user->authIdentifier());

        $this->user = $user;
        $this->resolved = true;
    }

    /**
     * Ends the session entirely.
     *
     * Destroying rather than only unsetting the identifier: anything else the
     * session accumulated belonged to that login too.
     */
    public function logout(): void
    {
        $this->session->destroy();

        $this->user = null;
        $this->resolved = true;
    }
}
