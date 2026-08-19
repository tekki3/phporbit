<?php

declare(strict_types=1);

namespace PhpOrbit\Container;

use Closure;
use PhpOrbit\Container\Exception\ContainerFrozen;
use PhpOrbit\Container\Exception\ServiceNotFound;

/**
 * The boot-phase container.
 *
 * Definitions are registered while the application boots and the container is
 * then frozen for the life of the process. Under a worker SAPI this object is
 * shared by every request that the process ever serves, so after freezing it
 * holds nothing mutable: singletons resolved here must be stateless.
 *
 * Anything that varies per request belongs in a {@see RequestScope}, obtained
 * from {@see enterRequest()}.
 */
final class Container
{
    /** @var array<string, Closure(Container): object> */
    private array $definitions = [];

    /** @var array<string, Closure(RequestScope): object> */
    private array $scopedDefinitions = [];

    /** @var array<string, object> */
    private array $singletons = [];

    private bool $frozen = false;

    /**
     * Registers a process-lifetime service.
     *
     * @template T of object
     * @param class-string<T>            $id
     * @param Closure(Container): T      $factory
     */
    public function singleton(string $id, Closure $factory): void
    {
        $this->assertNotFrozen($id);

        $this->definitions[$id] = $factory;
    }

    /**
     * Registers a service rebuilt for every request.
     *
     * The factory receives the {@see RequestScope}, not the container, so it
     * can reach other per-request services — the session, the matched route,
     * the authenticated user. Handing it the container instead would tempt a
     * factory into opening a second scope, which would silently miss
     * everything middleware published into the real one.
     *
     * @template T of object
     * @param class-string<T>          $id
     * @param Closure(RequestScope): T $factory
     */
    public function scoped(string $id, Closure $factory): void
    {
        $this->assertNotFrozen($id);

        $this->scopedDefinitions[$id] = $factory;
    }

    /**
     * Ends the boot phase. Further registration is a programming error.
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * Resolves a process-lifetime service.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        $instance = $this->singletons[$id] ??= $this->build($id);

        assert($instance instanceof $id);

        return $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]) || isset($this->scopedDefinitions[$id]);
    }

    /**
     * Whether this id resolves to a process-lifetime instance.
     */
    public function hasSingleton(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    /**
     * Whether this id is rebuilt per request.
     */
    public function hasScoped(string $id): bool
    {
        return isset($this->scopedDefinitions[$id]);
    }

    /**
     * Opens a disposable scope for one request.
     *
     * The returned object holds every per-request instance; dropping it at the
     * end of the request is what prevents state from reaching the next one.
     */
    public function enterRequest(): RequestScope
    {
        return new RequestScope($this, $this->scopedDefinitions);
    }

    /**
     * @param class-string $id
     */
    private function build(string $id): object
    {
        $factory = $this->definitions[$id] ?? null;

        if ($factory === null) {
            throw isset($this->scopedDefinitions[$id])
                ? new ServiceNotFound(sprintf(
                    'Service "%s" is request-scoped and cannot be resolved from the boot container. '
                    . 'Resolve it from the RequestScope instead.',
                    $id,
                ))
                : new ServiceNotFound(sprintf('Service "%s" is not registered.', $id));
        }

        return $factory($this);
    }

    private function assertNotFrozen(string $id): void
    {
        if ($this->frozen) {
            throw new ContainerFrozen(sprintf(
                'Cannot register "%s": the container was frozen when the application finished booting. '
                . 'Registering during a request would leak the definition into every later request '
                . 'served by this worker process.',
                $id,
            ));
        }
    }
}
