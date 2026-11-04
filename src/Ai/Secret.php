<?php

declare(strict_types=1);

namespace PhpOrbit\Ai;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

final class Secret
{
    public function __construct(#[SensitiveParameter] private readonly string $value)
    {
        if (trim($value) === '' || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('An AI API key must be nonblank and contain no whitespace or control characters.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('AI credentials cannot be serialized.');
    }
}
