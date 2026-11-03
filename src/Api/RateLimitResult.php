<?php

declare(strict_types=1);

namespace PhpOrbit\Api;

final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $remaining,
        public readonly int $resetsAt,
    ) {
    }
}
