<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Support;

final class HasDefault
{
    public function __construct(
        public readonly int $port = 8080,
    ) {
    }
}
