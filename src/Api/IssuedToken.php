<?php

declare(strict_types=1);

namespace PhpOrbit\Api;

use SensitiveParameter;

final class IssuedToken
{
    public function __construct(
        #[SensitiveParameter] public readonly string $value,
        public readonly string $id,
        public readonly int $expiresAt,
    ) {
    }

    /** @return array<string, string|int> */
    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]', 'id' => $this->id, 'expires_at' => $this->expiresAt];
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new \LogicException('Issued tokens must not be serialized.');
    }
}
