<?php

declare(strict_types=1);

namespace PhpOrbit\Routing;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;

/**
 * A controller class.
 *
 * One class per route rather than a class with many actions. That keeps the
 * signature statically checkable — a `[Controller::class, 'method']` pair can
 * only be called dynamically, which returns `mixed` and defeats the type
 * guarantees the rest of the framework relies on.
 *
 * Implementations are resolved from the {@see \PhpOrbit\Container\RequestScope}
 * per request, so constructor dependencies are autowired and anything held on
 * the instance dies with the request.
 */
interface Handler
{
    public function handle(ServerRequest $request): Response;
}
