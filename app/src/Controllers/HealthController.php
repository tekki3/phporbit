<?php

declare(strict_types=1);

namespace App\Controllers;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

/**
 * Liveness endpoint.
 *
 * Reports the SAPI so a deployment can confirm which process model it actually
 * got — the same application answers here under all four targets, and this is
 * the cheapest way to tell them apart from outside.
 */
final class HealthController implements Handler
{
    public function handle(ServerRequest $request): Response
    {
        return Response::json([
            'status' => 'ok',
            'sapi' => PHP_SAPI,
            'php' => PHP_VERSION,
        ]);
    }
}
