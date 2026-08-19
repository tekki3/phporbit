<?php

declare(strict_types=1);

namespace PhpOrbit\Log;

use Closure;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Middleware\Middleware;
use Throwable;

/**
 * Logs one line per request, including those that threw.
 *
 * Registered outermost so its timing covers every other layer. The query
 * string is deliberately omitted: it routinely carries tokens and search terms
 * that should not be duplicated into logs.
 */
final class LogRequests implements Middleware
{
    public function __construct(
        private readonly Logger $logger,
    ) {
    }

    public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
    {
        $started = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->logger->log(Level::Error, 'request failed', [
                'method' => $request->method->value,
                'path' => $request->uri->path,
                'exception' => $e::class,
                'duration_ms' => $this->elapsedMs($started),
            ]);

            throw $e;
        }

        $this->logger->log(
            $response->status->value >= 500 ? Level::Error : Level::Info,
            'request handled',
            [
                'method' => $request->method->value,
                'path' => $request->uri->path,
                'status' => $response->status->value,
                'duration_ms' => $this->elapsedMs($started),
            ],
        );

        return $response;
    }

    private function elapsedMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }
}
