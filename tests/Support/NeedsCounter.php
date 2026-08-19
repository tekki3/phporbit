<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Support;

final class NeedsCounter
{
    public function __construct(
        public readonly Counter $counter,
    ) {
    }
}
