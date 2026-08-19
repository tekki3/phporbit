<?php

declare(strict_types=1);

namespace PhpOrbit\Kernel;

use Closure;
use InvalidArgumentException;
use PhpOrbit\Container\Container;
use PhpOrbit\Middleware\Middleware;
use PhpOrbit\Routing\RouteCollection;

/**
 * Everything an application declares while booting.
 *
 * Passed as the single argument to {@see Application::boot()}, so there is one
 * object to discover rather than a growing positional signature. It is
 * consumed by boot and then thrown away — nothing holds a reference to it once
 * the application is serving.
 */
final class Blueprint
{
    /** @var list<Middleware> */
    private array $middleware = [];

    public function __construct(
        public readonly Container $container,
        public readonly RouteCollection $routes,
        public readonly bool $debug = false,
    ) {
    }

    /**
     * Appends global middleware, outermost first.
     *
     * Order is the registration order and it matters: a layer can only use
     * what an earlier one published. CSRF checking must come after the session
     * that holds the token, for instance.
     */
    public function middleware(Middleware ...$middleware): self
    {
        foreach ($middleware as $layer) {
            $this->middleware[] = $layer;
        }

        return $this;
    }

    /**
     * @return list<Middleware>
     */
    public function middlewareStack(): array
    {
        return $this->middleware;
    }

    /**
     * Loads route declarations from a file.
     *
     * The file must return a closure taking the {@see RouteCollection} and the
     * debug flag. It is `require`d here, during boot, so the routes still land
     * before the table is compiled and the container frozen — moving them to
     * their own file changes where they are written, not when they take effect.
     *
     * The returned value is checked rather than trusted: a routes file that
     * forgets to return anything would otherwise fail with "value of type null
     * is not callable", which says nothing about the actual mistake.
     */
    public function loadRoutes(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('No routes file at %s.', $path));
        }

        /** @var mixed $define */
        $define = require $path;

        if (!$define instanceof Closure) {
            throw new InvalidArgumentException(sprintf(
                'The routes file %s must return a closure taking (RouteCollection $routes, bool $debug); it returned %s.',
                $path,
                get_debug_type($define),
            ));
        }

        $define($this->routes, $this->debug);

        return $this;
    }
}
