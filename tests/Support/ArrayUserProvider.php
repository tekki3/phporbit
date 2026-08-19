<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Support;

use PhpOrbit\Auth\Identity;
use PhpOrbit\Auth\UserProvider;

/**
 * An in-memory user store, so authentication tests are about authentication
 * rather than about SQL.
 */
final class ArrayUserProvider implements UserProvider
{
    /** @var array<string, TestUser> */
    private array $byId = [];

    public function add(string $id, string $email, string $passwordHash): TestUser
    {
        $user = new TestUser($id, $email, $passwordHash);

        $this->byId[$id] = $user;

        return $user;
    }

    public function remove(string $id): void
    {
        unset($this->byId[$id]);
    }

    public function hashFor(string $id): string
    {
        $user = $this->byId[$id] ?? null;

        return $user === null ? '' : $user->passwordHash();
    }

    public function findByIdentifier(string $identifier): ?Identity
    {
        return $this->byId[$identifier] ?? null;
    }

    public function findByEmail(string $email): ?Identity
    {
        foreach ($this->byId as $user) {
            if ($user->email === mb_strtolower($email)) {
                return $user;
            }
        }

        return null;
    }

    public function updatePasswordHash(Identity $user, string $hash): void
    {
        $existing = $this->byId[$user->authIdentifier()] ?? null;

        if ($existing !== null) {
            $this->byId[$user->authIdentifier()] = new TestUser($existing->authIdentifier(), $existing->email, $hash);
        }
    }
}
