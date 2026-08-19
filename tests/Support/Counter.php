<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Support;

/**
 * A deliberately stateful service.
 *
 * Used to prove that per-request state cannot travel between requests: if this
 * object is ever shared across two requests, its count reveals it.
 */
final class Counter
{
    private int $count = 0;

    public function increment(): int
    {
        return ++$this->count;
    }

    public function count(): int
    {
        return $this->count;
    }
}
