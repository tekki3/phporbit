<?php

declare(strict_types=1);

namespace PhpOrbit\Routing;

use PhpOrbit\Http\Method;

/**
 * The outcome of routing one request.
 *
 * "No route" and "wrong method for an existing route" are distinct outcomes
 * because they produce different responses — 404 versus 405 with an Allow
 * header — and conflating them leaks less information than it appears to
 * while making correct clients harder to debug.
 */
final class MatchResult
{
    /** @var list<Method> */
    public readonly array $allowedMethods;

    /** @var array<string, string> */
    public readonly array $parameters;

    /**
     * @param list<Method>          $allowedMethods
     * @param array<string, string> $parameters
     */
    private function __construct(
        public readonly Outcome $outcome,
        public readonly ?Route $route = null,
        array $parameters = [],
        array $allowedMethods = [],
    ) {
        $this->parameters = $parameters;
        $this->allowedMethods = $allowedMethods;
    }

    /**
     * @param array<string, string> $parameters
     */
    public static function found(Route $route, array $parameters): self
    {
        return new self(Outcome::Found, $route, $parameters);
    }

    public static function notFound(): self
    {
        return new self(Outcome::NotFound);
    }

    /**
     * @param list<Method> $allowed
     */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(Outcome::MethodNotAllowed, allowedMethods: $allowed);
    }
}
