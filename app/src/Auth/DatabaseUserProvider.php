<?php

declare(strict_types=1);

namespace App\Auth;

use PhpOrbit\Auth\Identity;
use PhpOrbit\Auth\PasswordHasher;
use PhpOrbit\Auth\UserProvider;
use PhpOrbit\Database\Connection;

/**
 * Loads users from the `users` table.
 */
final class DatabaseUserProvider implements UserProvider
{
    public function __construct(
        private readonly Connection $database,
        private readonly PasswordHasher $hasher,
    ) {
    }

    public function findByIdentifier(string $identifier): ?Identity
    {
        // Identifiers come from the session and should always be numeric here;
        // anything else cannot match a row, so skip the query.
        if (preg_match('/^\d+$/', $identifier) !== 1) {
            return null;
        }

        return $this->hydrate($this->database->query('users')->where('id', '=', (int) $identifier)->first());
    }

    public function findByEmail(string $email): ?Identity
    {
        // Addresses are stored lowercased so that a login is not defeated by
        // the casing someone happened to type.
        return $this->hydrate(
            $this->database->query('users')->where('email', '=', $this->normalise($email))->first(),
        );
    }

    public function updatePasswordHash(Identity $user, string $hash): void
    {
        $this->database->query('users')
            ->where('id', '=', $user->authIdentifier())
            ->update(['password_hash' => $hash]);
    }

    public function create(string $email, string $password, string $displayName): User
    {
        $id = $this->database->query('users')->insert([
            'email' => $this->normalise($email),
            'password_hash' => $this->hasher->hash($password),
            'display_name' => $displayName,
            'avatar_path' => null,
            'created_at' => gmdate('c'),
        ]);

        $user = $this->findByIdentifier((string) $id);

        assert($user instanceof User);

        return $user;
    }

    public function setAvatar(User $user, ?string $path): void
    {
        $this->database->query('users')
            ->where('id', '=', $user->id)
            ->update(['avatar_path' => $path]);
    }

    public function count(): int
    {
        return $this->database->query('users')->count();
    }

    /**
     * @param array<string, scalar|null>|null $row
     */
    private function hydrate(?array $row): ?User
    {
        return $row === null ? null : User::fromRow($row);
    }

    private function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
