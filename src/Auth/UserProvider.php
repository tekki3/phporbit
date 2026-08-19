<?php

declare(strict_types=1);

namespace PhpOrbit\Auth;

interface UserProvider
{
    public function findByIdentifier(string $identifier): ?Identity;

    public function findByEmail(string $email): ?Identity;

    /**
     * Persists a rehashed password.
     *
     * Called when a successful login is verified against a hash produced with
     * outdated parameters, so the cost can be raised over time without forcing
     * a reset.
     */
    public function updatePasswordHash(Identity $user, string $hash): void;
}
