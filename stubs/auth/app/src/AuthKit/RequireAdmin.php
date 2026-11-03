<?php

declare(strict_types=1);

namespace App\AuthKit;

use Closure;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Middleware\Middleware;

final class RequireAdmin implements Middleware
{
    public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
    {
        $user = $scope->get(Authenticator::class)->user();
        if (!$user instanceof User || !$user->isAdmin) {
            return Response::text('Administrator access is required.', Status::Forbidden)->withHeader('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
