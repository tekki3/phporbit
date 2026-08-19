<?php

declare(strict_types=1);

namespace PhpOrbit\Middleware;

use Closure;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;

/**
 * Composes middleware around a destination handler.
 *
 * The chain is built by folding from the inside out, so the first entry in the
 * list is the outermost layer — it sees the request first and the response
 * last. A middleware that passes a modified request to `$next` changes what
 * every inner layer sees, which is how route parameters and the session reach
 * the handler.
 */
final class Pipeline
{
    /**
     * @param list<Middleware> $middleware outermost first
     * @param Closure(ServerRequest): Response $destination
     */
    public static function run(
        array $middleware,
        ServerRequest $request,
        RequestScope $scope,
        Closure $destination,
    ): Response {
        $next = $destination;

        foreach (array_reverse($middleware) as $layer) {
            $inner = $next;

            $next = static fn (ServerRequest $r): Response => $layer->process($r, $scope, $inner);
        }

        return $next($request);
    }
}
