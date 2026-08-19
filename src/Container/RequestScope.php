<?php

declare(strict_types=1);

namespace PhpOrbit\Container;

use Closure;
use PhpOrbit\Container\Exception\CannotAutowire;
use PhpOrbit\Container\Exception\ScopeClosed;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * Per-request instances, discarded when the request ends.
 *
 * This is the only place mutable state may live during a request. The scope is
 * closed by the kernel in a finally block, which both releases resources and
 * makes any later use of a stale scope throw instead of silently serving one
 * request's data to another.
 *
 * Autowiring lives here rather than on the {@see Container} on purpose. An
 * autowired instance cached at container level would be shared by every later
 * request in a worker process — exactly the leak the two-phase split exists to
 * prevent. Resolving an unregistered class therefore always produces a
 * per-request instance, and {@see Container::get()} still refuses.
 */
final class RequestScope
{
    /** @var array<string, object> */
    private array $instances = [];

    /** @var list<Closure(): void> */
    private array $releases = [];

    /** @var array<string, true> guards against a dependency cycle */
    private array $resolving = [];

    private bool $closed = false;

    /**
     * @param array<string, Closure(RequestScope): object> $definitions
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $definitions,
    ) {
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        $this->assertOpen();

        if (!isset($this->instances[$id])) {
            $this->instances[$id] = $this->resolve($id);
        }

        $instance = $this->instances[$id];

        assert($instance instanceof $id);

        return $instance;
    }

    /**
     * Attaches an instance built for this request only.
     *
     * Used by middleware to publish something the request produced — the
     * session, the matched route — to code further down the pipeline. It
     * cannot reach the boot container, so it cannot outlive the request.
     *
     * @template T of object
     * @param class-string<T> $id
     * @param T               $instance
     */
    public function provide(string $id, object $instance): void
    {
        $this->assertOpen();

        if (isset($this->instances[$id])) {
            throw new CannotAutowire(sprintf(
                'An instance of "%s" has already been provided for this request.',
                $id,
            ));
        }

        $this->instances[$id] = $instance;
    }

    /**
     * Whether an instance for this id already exists in this request.
     *
     * Lets a middleware behave differently when an optional collaborator —
     * a matched route, an authenticated user — was never published.
     */
    public function provided(string $id): bool
    {
        return isset($this->instances[$id]);
    }

    /**
     * Registers a teardown callback run when the request ends.
     *
     * Used for anything that must be released deterministically — open
     * transactions, file handles, locks — including on the error path.
     *
     * @param Closure(): void $release
     */
    public function onClose(Closure $release): void
    {
        $this->assertOpen();

        $this->releases[] = $release;
    }

    /**
     * Disposes the scope. Safe to call more than once.
     *
     * Every teardown callback runs even if an earlier one throws, so a single
     * failing release cannot strand the rest.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $failure = null;

        foreach (array_reverse($this->releases) as $release) {
            try {
                $release();
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        $this->instances = [];
        $this->releases = [];

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @param class-string $id
     */
    private function resolve(string $id): object
    {
        $factory = $this->definitions[$id] ?? null;

        if ($factory !== null) {
            return $factory($this);
        }

        // A registered singleton is shared with the whole process by design.
        if ($this->container->hasSingleton($id)) {
            return $this->container->get($id);
        }

        return $this->autowire($id);
    }

    /**
     * Constructs an unregistered class by reflecting its constructor.
     *
     * Only object-typed parameters are resolved. A scalar the container cannot
     * know about is an error rather than a guess, because guessing here would
     * silently inject a default nobody chose.
     *
     * @param class-string $id
     */
    private function autowire(string $id): object
    {
        if (isset($this->resolving[$id])) {
            throw new CannotAutowire(sprintf(
                'Circular dependency while resolving "%s": %s.',
                $id,
                implode(' -> ', array_keys($this->resolving)) . ' -> ' . $id,
            ));
        }

        // interface_exists() is checked separately: class_exists() is false for
        // an interface, which would otherwise produce a misleading "no such
        // class" message for the common case of forgetting to bind one.
        if (interface_exists($id) || (class_exists($id) && (new ReflectionClass($id))->isAbstract())) {
            throw new CannotAutowire(sprintf(
                'Cannot resolve "%s": it is an interface or abstract class, so it must be '
                . 'registered with an explicit factory.',
                $id,
            ));
        }

        if (!class_exists($id)) {
            throw new CannotAutowire(sprintf(
                'Cannot resolve "%s": it is not registered and no such class exists.',
                $id,
            ));
        }

        $reflection = new ReflectionClass($id);

        if (!$reflection->isInstantiable()) {
            throw new CannotAutowire(sprintf(
                'Cannot resolve "%s": it cannot be instantiated — check for a private constructor.',
                $id,
            ));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $this->resolving[$id] = true;

        try {
            $arguments = [];

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    /** @var class-string $dependency */
                    $dependency = $type->getName();
                    $arguments[] = $this->get($dependency);

                    continue;
                }

                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();

                    continue;
                }

                throw new CannotAutowire(sprintf(
                    'Cannot resolve "$%s" of %s::__construct(): it has no class type and no default. '
                    . 'Register "%s" with an explicit factory.',
                    $parameter->getName(),
                    $id,
                    $id,
                ));
            }

            return $reflection->newInstanceArgs($arguments);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new ScopeClosed(
                'This request scope has been closed. Holding a reference to it beyond the '
                . 'request it belongs to would expose one request\'s state to another.',
            );
        }
    }
}
