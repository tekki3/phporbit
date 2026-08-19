<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use InvalidArgumentException;

/**
 * How long an instance of a generated class lives.
 *
 * This is the one decision a new class in this framework cannot avoid, because
 * the four deployment targets split into two process models: under a worker,
 * anything mutable that outlives a request is shared with the next visitor.
 * Making it an argument to `make:class` puts the choice in front of the
 * developer while the file is being created, rather than after a leak.
 *
 * `Autowired` is the default and needs no registration at all — an unregistered
 * class is constructed from the request scope, which is per-request by
 * construction and therefore the safe option to reach for without thinking.
 */
enum Lifetime: string
{
    /** Built per request by the request scope; nothing to register. */
    case Autowired = 'autowired';

    /** Registered with `scoped()`: rebuilt for every request. */
    case Scoped = 'scoped';

    /** Registered with `singleton()`: one instance per process, so stateless. */
    case Singleton = 'singleton';

    /**
     * Resolves the two CLI flags, which are mutually exclusive.
     *
     * The refusal lives here rather than in the `orbit` script so both copies of
     * that script — this repository's and the one a scaffolded project gets —
     * behave identically without repeating the rule.
     */
    public static function fromFlags(bool $singleton, bool $scoped): self
    {
        if ($singleton && $scoped) {
            throw new InvalidArgumentException(
                'Pass either --singleton or --scoped, not both: a class has one lifetime.',
            );
        }

        return match (true) {
            $singleton => self::Singleton,
            $scoped => self::Scoped,
            default => self::Autowired,
        };
    }

    public function describe(): string
    {
        return match ($this) {
            self::Autowired => 'autowired per request, so nothing to register',
            self::Scoped => 'scoped, rebuilt for every request',
            self::Singleton => 'a singleton: one instance per process, so it must be stateless',
        };
    }

    /**
     * The registration line for `app/bootstrap.php`, or null when the container
     * needs no help.
     *
     * A scoped factory receives the {@see \PhpOrbit\Container\RequestScope} and
     * a singleton factory receives nothing, but neither parameter is emitted
     * here: a class generated with no constructor has no collaborator to
     * resolve, and an unused parameter in a pasted line reads as a mistake.
     *
     * @param string $class the short class name, which is what the import brings into scope
     */
    public function registration(string $class): ?string
    {
        return match ($this) {
            self::Autowired => null,
            self::Scoped => sprintf(
                '$app->container->scoped(%1$s::class, static fn (): %1$s => new %1$s());',
                $class,
            ),
            self::Singleton => sprintf(
                '$app->container->singleton(%1$s::class, static fn (): %1$s => new %1$s());',
                $class,
            ),
        };
    }
}
