<?php

declare(strict_types=1);

namespace App\Auth;

use PhpOrbit\Auth\Identity;

/**
 * The demo application's user.
 *
 * Implements {@see Identity} with the two things authentication needs; the
 * rest is the application's own business.
 */
final class User implements Identity
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly ?string $avatarPath,
        private readonly string $passwordHash,
    ) {
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function fromRow(array $row): self
    {
        $avatar = $row['avatar_path'] ?? null;

        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['email'] ?? ''),
            (string) ($row['display_name'] ?? ''),
            $avatar === null || $avatar === '' ? null : (string) $avatar,
            (string) ($row['password_hash'] ?? ''),
        );
    }

    public function authIdentifier(): string
    {
        return (string) $this->id;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }
}
