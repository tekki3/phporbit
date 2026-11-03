<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Support\ApiDefinition;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class OpenApiController implements Handler
{
    public function handle(ServerRequest $request): Response
    {
        return Response::json(ApiDefinition::document()->document());
    }
}
