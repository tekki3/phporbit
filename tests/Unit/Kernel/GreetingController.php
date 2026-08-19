<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Kernel;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

/**
 * A controller with no dependencies, used to prove class handlers resolve.
 */
final class GreetingController implements Handler
{
    public function handle(ServerRequest $request): Response
    {
        return Response::text('hi ' . $request->attribute('name'));
    }
}
