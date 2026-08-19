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
 * The boot-phase route builder.
 *
 * Mutable by design, but only while the application boots. It is converted
 * into an immutable {@see Router} by {@see compile()} and then discarded, so
 * no request ever holds a reference to something it could add routes to.
 *
 * @phpstan-type RouteHandler Closure(ServerRequest, RequestScope): Response|class-string<Handler>
 */
final class RouteCollection
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, true> */
    private array $names = [];

    private string $prefix = '';

    /** @var list<Middleware> */
    private array $groupMiddleware = [];

    /**
     * @param RouteHandler $handler
     * @param list<Middleware> $middleware
     */
    public function get(
        string $pattern,
        Closure|string $handler,
        ?string $name = null,
        array $middleware = [],
        bool $csrfExempt = false,
    ): self {
        return $this->add(Method::Get, $pattern, $handler, $name, $csrfExempt, $middleware);
    }

    /**
     * @param RouteHandler $handler
     * @param list<Middleware> $middleware
     */
    public function post(
        string $pattern,
        Closure|string $handler,
        ?string $name = null,
        array $middleware = [],
        bool $csrfExempt = false,
    ): self {
        return $this->add(Method::Post, $pattern, $handler, $name, $csrfExempt, $middleware);
    }

    /**
     * @param RouteHandler $handler
     * @param list<Middleware> $middleware
     */
    public function put(
        string $pattern,
        Closure|string $handler,
        ?string $name = null,
        array $middleware = [],
        bool $csrfExempt = false,
    ): self {
        return $this->add(Method::Put, $pattern, $handler, $name, $csrfExempt, $middleware);
    }

    /**
     * @param RouteHandler $handler
     * @param list<Middleware> $middleware
     */
    public function patch(
        string $pattern,
        Closure|string $handler,
        ?string $name = null,
        array $middleware = [],
        bool $csrfExempt = false,
    ): self {
        return $this->add(Method::Patch, $pattern, $handler, $name, $csrfExempt, $middleware);
    }

    /**
     * @param RouteHandler $handler
     * @param list<Middleware> $middleware
     */
    public function delete(
        string $pattern,
        Closure|string $handler,
        ?string $name = null,
        array $middleware = [],
        bool $csrfExempt = false,
    ): self {
        return $this->add(Method::Delete, $pattern, $handler, $name, $csrfExempt, $middleware);
    }

    /**
     * Applies middleware to every route defined in the callback.
     *
     * The same thing as a prefix-less group, given its own name because
     * "these routes require a signed-in user" is a different statement from
     * "these routes live under /admin", and a reader should not have to
     * decode an empty string to tell which one was meant.
     *
     * @param list<Middleware> $middleware
     * @param Closure(RouteCollection): void $define
     */
    public function withMiddleware(array $middleware, Closure $define): self
    {
        return $this->group('', $define, $middleware);
    }

    /**
     * Registers every route defined in the callback under a path prefix, with
     * shared middleware.
     *
     * @param Closure(RouteCollection): void $define
     * @param list<Middleware> $middleware
     */
    public function group(string $prefix, Closure $define, array $middleware = []): self
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->prefix = $previousPrefix . Router::normalise($prefix, keepRoot: false);
        $this->groupMiddleware = [...$previousMiddleware, ...$middleware];

        try {
            $define($this);
        } finally {
            // Restored in a finally so a throwing callback cannot leak its
            // prefix into routes registered afterwards.
            $this->prefix = $previousPrefix;
            $this->groupMiddleware = $previousMiddleware;
        }

        return $this;
    }

    /**
     * @param RouteHandler $handler
     * @param list<Middleware> $middleware
     */
    public function add(
        Method $method,
        string $pattern,
        Closure|string $handler,
        ?string $name = null,
        bool $csrfExempt = false,
        array $middleware = [],
    ): self {
        if ($name !== null) {
            if (isset($this->names[$name])) {
                throw new InvalidRoutePattern(sprintf('Route name "%s" is already taken.', $name));
            }

            $this->names[$name] = true;
        }

        $this->routes[] = new Route(
            $method,
            Router::normalise($this->prefix . $pattern),
            $handler,
            $name,
            $csrfExempt,
            [...$this->groupMiddleware, ...$middleware],
        );

        return $this;
    }

    public function compile(): Router
    {
        return new Router($this->routes);
    }
}
