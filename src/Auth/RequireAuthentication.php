<?php

declare(strict_types=1);

namespace PhpOrbit\Auth;

use Closure;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Middleware\Middleware;
use PhpOrbit\Session\Session;

/**
 * Refuses a request from someone who is not logged in.
 *
 * Registered per route rather than globally, so guarding a route is a visible
 * decision at the point it is declared.
 *
 * Browsers get a redirect to the login page; anything else gets a 401. Sending
 * a redirect to an API client turns an auth failure into a confusing 200 from
 * whatever the login page returns.
 */
final class RequireAuthentication implements Middleware
{
    public const INTENDED_KEY = '_auth_intended';

    public function __construct(
        private readonly string $redirectTo = '/login',
    ) {
    }

    public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
    {
        if ($scope->get(Authenticator::class)->check()) {
            return $next($request);
        }

        if (!$this->wantsHtml($request)) {
            return Response::json(['error' => 'authentication required'], Status::Unauthorized);
        }

        // Remember where they were going, but only for safe methods: replaying
        // a POST after login would repeat a side effect they never confirmed.
        if ($request->method === Method::Get && $scope->provided(Session::class)) {
            $scope->get(Session::class)->set(self::INTENDED_KEY, $request->uri->path);
        }

        return Response::redirect($this->redirectTo);
    }

    private function wantsHtml(ServerRequest $request): bool
    {
        $accept = $request->headers->first('Accept');

        return $accept === null || str_contains($accept, 'text/html') || str_contains($accept, '*/*');
    }
}
