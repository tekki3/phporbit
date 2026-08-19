<?php

declare(strict_types=1);

namespace PhpOrbit\Routing;

use Closure;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Middleware\Middleware;
use PhpOrbit\Routing\Exception\InvalidRoutePattern;

/**
 * A single route, compiled once during boot.
 *
 * The regex and parameter names are derived in the constructor rather than at
 * match time: under a worker the table is built before any request arrives, so
 * compilation cost is paid once per process instead of once per request.
 */
final class Route
{
    /** @var list<string> */
    public readonly array $parameters;

    /** @var list<Middleware> */
    public readonly array $middleware;

    private readonly ?string $regex;

    /**
     * @param Closure(ServerRequest, RequestScope): Response|class-string<Handler> $handler
     * @param list<Middleware> $middleware
     */
    public function __construct(
        public readonly Method $method,
        public readonly string $pattern,
        public readonly Closure|string $handler,
        public readonly ?string $name = null,
        public readonly bool $csrfExempt = false,
        array $middleware = [],
    ) {
        [$this->regex, $this->parameters] = self::compile($pattern);
        $this->middleware = $middleware;

        if (is_string($handler)) {
            self::assertHandlerClass($handler, $pattern);
        }
    }

    /**
     * Runs the route's handler.
     *
     * A controller class is resolved from the request scope, so its
     * constructor dependencies are autowired per request.
     */
    public function invoke(ServerRequest $request, RequestScope $scope): Response
    {
        if ($this->handler instanceof Closure) {
            return ($this->handler)($request, $scope);
        }

        return $scope->get($this->handler)->handle($request);
    }

    /**
     * Whether this route contains no parameters and can be matched by hash.
     */
    public function isStatic(): bool
    {
        return $this->regex === null;
    }

    /**
     * Attempts to match a normalised path, returning captured parameters.
     *
     * @return array<string, string>|null null when the path does not match
     */
    public function match(string $path): ?array
    {
        if ($this->regex === null) {
            return $this->pattern === $path ? [] : null;
        }

        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];
        foreach ($this->parameters as $name) {
            $parameters[$name] = $matches[$name] ?? '';
        }

        return $parameters;
    }

    /**
     * A controller that does not implement {@see Handler} would only fail when
     * its route is first requested, so it is rejected at boot instead.
     */
    private static function assertHandlerClass(string $handler, string $pattern): void
    {
        if (!class_exists($handler)) {
            throw new InvalidRoutePattern(sprintf(
                'Route "%s" refers to handler class "%s", which does not exist.',
                $pattern,
                $handler,
            ));
        }

        if (!is_subclass_of($handler, Handler::class)) {
            throw new InvalidRoutePattern(sprintf(
                'Route "%s" refers to "%s", which does not implement %s.',
                $pattern,
                $handler,
                Handler::class,
            ));
        }
    }

    /**
     * Compiles `/users/{id}` or `/users/{id:\d+}` into a regex.
     *
     * An unconstrained parameter deliberately excludes `/`, so a single
     * placeholder can never swallow multiple path segments — that behaviour is
     * a common source of routes matching far more than their author intended.
     *
     * @return array{0: string|null, 1: list<string>}
     */
    private static function compile(string $pattern): array
    {
        if ($pattern === '' || $pattern[0] !== '/') {
            throw new InvalidRoutePattern(sprintf('Route pattern "%s" must start with "/".', $pattern));
        }

        if (!str_contains($pattern, '{')) {
            return [null, []];
        }

        $parameters = [];
        $regex = '';
        $offset = 0;

        while (preg_match('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::(.+?))?\}/', $pattern, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $whole = $m[0][0];
            $start = $m[0][1];
            $name = $m[1][0];

            if (in_array($name, $parameters, true)) {
                throw new InvalidRoutePattern(sprintf(
                    'Route pattern "%s" declares parameter "%s" more than once.',
                    $pattern,
                    $name,
                ));
            }

            $parameters[] = $name;

            $regex .= preg_quote(substr($pattern, $offset, $start - $offset), '#');
            $constraint = $m[2][0] ?? '[^/]+';
            $regex .= sprintf('(?P<%s>%s)', $name, $constraint);

            $offset = $start + strlen($whole);
        }

        $regex .= preg_quote(substr($pattern, $offset), '#');

        $compiled = '#^' . $regex . '$#u';

        // Reject a bad constraint at boot rather than on the first request
        // that happens to reach this route.
        if (@preg_match($compiled, '') === false) {
            throw new InvalidRoutePattern(sprintf(
                'Route pattern "%s" compiles to an invalid regex; check its constraints.',
                $pattern,
            ));
        }

        return [$compiled, $parameters];
    }
}
