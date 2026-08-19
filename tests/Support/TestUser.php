<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Support;

use PhpOrbit\Auth\Identity;

final class TestUser implements Identity
{
    public function __construct(
        private readonly string $id,
        public readonly string $email,
        private readonly string $passwordHash,
    ) {
    }

    public function authIdentifier(): string
    {
        return $this->id;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }
}
