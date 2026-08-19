<?php

declare(strict_types=1);

namespace PhpOrbit\Security;

use Closure;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Middleware\Middleware;
use PhpOrbit\Routing\Route;
use PhpOrbit\Session\Session;

/**
 * Rejects state-changing requests that do not carry a valid CSRF token.
 *
 * Protection is on by default and opted out of per route, rather than opted
 * into: a developer who forgets to think about CSRF gets the safe behaviour.
 *
 * Must be registered after {@see \PhpOrbit\Session\SessionMiddleware}, which
 * publishes the session this reads.
 */
final class CsrfMiddleware implements Middleware
{
    public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
    {
        if (!$request->method->isStateChanging()) {
            return $next($request);
        }

        if ($this->isExempt($scope)) {
            return $next($request);
        }

        // No session middleware means no token store, which is a wiring
        // mistake rather than an attack — fail loudly instead of allowing it.
        if (!$scope->provided(Session::class)) {
            return Response::text(
                'CSRF protection requires SessionMiddleware to run first.',
                Status::InternalServerError,
            );
        }

        if (!Csrf::isValid($scope->get(Session::class), $this->presentedToken($request))) {
            return Response::text('CSRF token missing or invalid.', Status::Forbidden);
        }

        return $next($request);
    }

    /**
     * A route with no match (a 404) has nothing to exempt, and cannot change
     * state anyway.
     */
    private function isExempt(RequestScope $scope): bool
    {
        return $scope->provided(Route::class) && $scope->get(Route::class)->csrfExempt;
    }

    /**
     * The form field takes precedence, with a header for fetch/XHR callers.
     */
    private function presentedToken(ServerRequest $request): ?string
    {
        return $request->form(Csrf::FIELD_NAME)
            ?? $request->headers->first(Csrf::HEADER_NAME);
    }
}
