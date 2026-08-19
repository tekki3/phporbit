<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A request-lifetime counter, registered as scoped.
 *
 * The counterpart to {@see WorkerStats}. However many requests a worker has
 * served, this must read 1 — if it ever reads higher, per-request state is
 * surviving into the next request and the framework's central invariant is
 * broken.
 */
final class ScopedProbe
{
    private int $touches = 0;

    public function touch(): int
    {
        return ++$this->touches;
    }
}
