<?php

declare(strict_types=1);

namespace PhpOrbit\Routing;

use PhpOrbit\Http\Method;
use PhpOrbit\Http\ServerRequest;

/**
 * The compiled, immutable route table.
 *
 * Static routes go into a hash keyed by method and path, so the common case
 * costs one array lookup no matter how many routes exist. Only parameterised
 * routes are scanned, and they are tried in registration order.
 */
final class Router
{
    /** @var array<string, array<string, Route>> method => path => route */
    private readonly array $static;

    /** @var list<Route> */
    private readonly array $dynamic;

    /** @var list<Route> */
    private readonly array $all;

    /** @var array<string, Route> */
    private readonly array $named;

    /**
     * @param list<Route> $routes
     */
    public function __construct(array $routes)
    {
        $this->all = $routes;

        $static = [];
        $dynamic = [];
        $named = [];

        foreach ($routes as $route) {
            if ($route->name !== null) {
                $named[$route->name] = $route;
            }

            if ($route->isStatic()) {
                $static[$route->method->value][$route->pattern] = $route;
                continue;
            }

            $dynamic[] = $route;
        }

        $this->static = $static;
        $this->dynamic = $dynamic;
        $this->named = $named;
    }

    /**
     * Builds the path for a named route.
     *
     * Generating links from names rather than writing them out means a pattern
     * can be changed in one place. Missing or non-conforming parameters are an
     * error here, so a broken link surfaces where it is built rather than as a
     * 404 for whoever clicks it.
     *
     * @param array<string, string|int> $parameters
     */
    public function urlFor(string $name, array $parameters = []): string
    {
        $route = $this->named[$name] ?? throw new UnknownRoute(sprintf(
            'No route is named "%s". Known names: %s.',
            $name,
            $this->named === [] ? '(none)' : implode(', ', array_keys($this->named)),
        ));

        $url = $route->pattern;

        foreach ($route->parameters as $parameter) {
            if (!isset($parameters[$parameter])) {
                throw new UnknownRoute(sprintf(
                    'Route "%s" needs a value for {%s}.',
                    $name,
                    $parameter,
                ));
            }

            $value = (string) $parameters[$parameter];

            $url = (string) preg_replace(
                sprintf('/\{%s(?::.+?)?\}/', preg_quote($parameter, '/')),
                str_replace('%', '%%', rawurlencode($value)),
                $url,
                1,
            );
        }

        // Restore any percent signs protected from the replacement above.
        $url = str_replace('%%', '%', $url);

        // The generated path must actually match the route it came from; if it
        // does not, a parameter violated the pattern's own constraint.
        if ($route->match(self::normalise($url)) === null) {
            throw new UnknownRoute(sprintf(
                'The values given for route "%s" do not satisfy its pattern "%s".',
                $name,
                $route->pattern,
            ));
        }

        return $url;
    }

    public function hasName(string $name): bool
    {
        return isset($this->named[$name]);
    }

    /**
     * Every registered route, in registration order.
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->all;
    }

    public function match(ServerRequest $request): MatchResult
    {
        $path = self::normalise($request->uri->path);
        $method = $request->method;

        // HEAD is served by the GET handler; the kernel strips the body.
        $lookup = $method === Method::Head ? Method::Get : $method;

        $route = $this->static[$lookup->value][$path] ?? null;
        if ($route !== null) {
            return MatchResult::found($route, []);
        }

        foreach ($this->dynamic as $candidate) {
            if ($candidate->method !== $lookup) {
                continue;
            }

            $parameters = $candidate->match($path);
            if ($parameters !== null) {
                return MatchResult::found($candidate, $parameters);
            }
        }

        $allowed = $this->methodsFor($path);

        return $allowed === [] ? MatchResult::notFound() : MatchResult::methodNotAllowed($allowed);
    }

    /**
     * Which methods any route would accept for this path.
     *
     * @return list<Method>
     */
    private function methodsFor(string $path): array
    {
        $allowed = [];

        foreach ($this->static as $method => $paths) {
            if (isset($paths[$path])) {
                $allowed[] = Method::from($method);
            }
        }

        foreach ($this->dynamic as $route) {
            if ($route->match($path) !== null && !in_array($route->method, $allowed, true)) {
                $allowed[] = $route->method;
            }
        }

        return $allowed;
    }

    /**
     * Collapses a path to its canonical form for matching.
     *
     * `/users` and `/users/` address the same resource, so both are stored and
     * looked up without the trailing slash. Doing this in one place keeps
     * registration and matching from disagreeing.
     */
    public static function normalise(string $path, bool $keepRoot = true): string
    {
        if ($path === '' || $path === '/') {
            return $keepRoot ? '/' : '';
        }

        return rtrim($path, '/');
    }
}
