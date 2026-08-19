<?php

declare(strict_types=1);

namespace PhpOrbit\Sapi;

use PhpOrbit\Kernel\Application;

/**
 * A deployment target.
 *
 * Implementations are the only code in phporbit permitted to touch
 * superglobals, `header()`, `echo`, or raw sockets. Their entire job is to
 * turn whatever the environment provides into a
 * {@see \PhpOrbit\Http\ServerRequest} and write a
 * {@see \PhpOrbit\Http\Response} back out.
 *
 * Everything above this boundary is identical across all four targets.
 */
interface Sapi
{
    /**
     * Serves requests until the process should end.
     *
     * Per-request adapters return after one request; worker adapters block in
     * a loop and return only on shutdown.
     */
    public function run(Application $app): void;
}
