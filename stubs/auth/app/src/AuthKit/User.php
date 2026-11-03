<?php

declare(strict_types=1);

namespace App\AuthKit;

use PhpOrbit\Auth\Identity;

final class User implements Identity
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly bool $isAdmin,
        private readonly string $hash,
    ) {
    }

    public function authIdentifier(): string
    {
        return (string) $this->id;
    }

    public function passwordHash(): string
    {
        return $this->hash;
    }
}
