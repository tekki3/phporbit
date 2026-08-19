<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Routing;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class GreetingRoute implements Handler
{
    public function handle(ServerRequest $request): Response
    {
        return Response::text('ok');
    }
}
