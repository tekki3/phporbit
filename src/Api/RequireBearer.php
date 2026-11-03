<?php

declare(strict_types=1);

namespace PhpOrbit\Api;

use Closure;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Middleware\Middleware;

final class RequireBearer implements Middleware
{
    /** @param list<string> $scopes */
    public function __construct(private readonly array $scopes = [])
    {
    }

    public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
    {
        $headers = $request->headers->all('Authorization');
        if (count($headers) !== 1 || preg_match('/^Bearer ([A-Za-z0-9._~-]+)$/iD', $headers[0], $match) !== 1) {
            return (new ApiError(Status::Unauthorized, 'A Bearer token is required.'))->response()
                ->withHeader('WWW-Authenticate', 'Bearer realm="api"');
        }
        try {
            $principal = $scope->get(Tokens::class)->authenticate($match[1]);
        } catch (ApiError $error) {
            return $error->response()->withHeader('WWW-Authenticate', 'Bearer realm="api", error="invalid_token"');
        }
        foreach ($this->scopes as $required) {
            if (!$principal->permits($required)) {
                return (new ApiError(Status::Forbidden, 'The token does not have the required scope.'))->response()
                    ->withHeader('WWW-Authenticate', 'Bearer error="insufficient_scope"');
            }
        }
        $scope->provide(Principal::class, $principal);

        return $next($request);
    }
}
