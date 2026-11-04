<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class SwaggerController implements Handler
{
    public function handle(ServerRequest $request): Response
    {
        return (new \PhpOrbit\Api\SwaggerController())->handle($request);
    }
}
