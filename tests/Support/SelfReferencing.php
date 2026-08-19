<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Support;

/**
 * Depends on itself, so resolving it must be refused rather than recursed.
 */
final class SelfReferencing
{
    public function __construct(
        public readonly SelfReferencing $self,
    ) {
    }
}
