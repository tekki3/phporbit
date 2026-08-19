<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A process-lifetime counter, registered as a singleton.
 *
 * Its whole purpose is to be visible on the self-check page next to
 * {@see ScopedProbe}. Under `orbit serve` or FrankenPHP this number climbs
 * with every reload, proving the process really is long-lived; under nginx or
 * Apache it stays at 1, because the process is new each time.
 */
final class WorkerStats
{
    private int $requests = 0;

    private readonly float $bootedAt;

    public function __construct()
    {
        $this->bootedAt = microtime(true);
    }

    public function recordRequest(): int
    {
        return ++$this->requests;
    }

    public function requests(): int
    {
        return $this->requests;
    }

    public function uptimeSeconds(): float
    {
        return round(microtime(true) - $this->bootedAt, 1);
    }
}
