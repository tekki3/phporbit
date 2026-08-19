<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Container;

use PhpOrbit\Container\Container;
use PhpOrbit\Container\Exception\ContainerFrozen;
use PhpOrbit\Container\Exception\ScopeClosed;
use PhpOrbit\Container\Exception\ServiceNotFound;
use PhpOrbit\Tests\Support\Counter;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function test_a_singleton_is_built_once(): void
    {
        $container = new Container();
        $container->singleton(Counter::class, static fn (): Counter => new Counter());
        $container->freeze();

        self::assertSame($container->get(Counter::class), $container->get(Counter::class));
    }

    public function test_a_scoped_service_is_rebuilt_per_scope(): void
    {
        $container = new Container();
        $container->scoped(Counter::class, static fn (): Counter => new Counter());
        $container->freeze();

        $first = $container->enterRequest();
        $second = $container->enterRequest();

        self::assertNotSame($first->get(Counter::class), $second->get(Counter::class));
    }

    public function test_a_scoped_service_is_shared_within_one_scope(): void
    {
        $container = new Container();
        $container->scoped(Counter::class, static fn (): Counter => new Counter());
        $container->freeze();

        $scope = $container->enterRequest();

        self::assertSame($scope->get(Counter::class), $scope->get(Counter::class));
    }

    /**
     * Registering after boot is the classic worker leak: the definition would
     * persist into every later request served by the process.
     */
    public function test_registration_after_freezing_is_rejected(): void
    {
        $container = new Container();
        $container->freeze();

        $this->expectException(ContainerFrozen::class);

        $container->singleton(Counter::class, static fn (): Counter => new Counter());
    }

    public function test_resolving_a_scoped_service_from_the_boot_container_is_rejected(): void
    {
        $container = new Container();
        $container->scoped(Counter::class, static fn (): Counter => new Counter());
        $container->freeze();

        $this->expectException(ServiceNotFound::class);
        $this->expectExceptionMessageMatches('/request-scoped/');

        $container->get(Counter::class);
    }

    public function test_an_unregistered_service_is_reported(): void
    {
        $container = new Container();
        $container->freeze();

        $this->expectException(ServiceNotFound::class);

        $container->get(Counter::class);
    }

    public function test_closing_a_scope_runs_teardown_in_reverse_order(): void
    {
        $container = new Container();
        $container->freeze();

        $order = [];
        $scope = $container->enterRequest();
        $scope->onClose(static function () use (&$order): void {
            $order[] = 'first';
        });
        $scope->onClose(static function () use (&$order): void {
            $order[] = 'second';
        });

        $scope->close();

        self::assertSame(['second', 'first'], $order);
    }

    public function test_a_closed_scope_cannot_be_reused(): void
    {
        $container = new Container();
        $container->scoped(Counter::class, static fn (): Counter => new Counter());
        $container->freeze();

        $scope = $container->enterRequest();
        $scope->close();

        $this->expectException(ScopeClosed::class);

        $scope->get(Counter::class);
    }

    public function test_closing_is_idempotent(): void
    {
        $container = new Container();
        $container->freeze();

        $calls = 0;
        $scope = $container->enterRequest();
        $scope->onClose(static function () use (&$calls): void {
            $calls++;
        });

        $scope->close();
        $scope->close();

        self::assertSame(1, $calls);
    }

    /**
     * One failing release must not strand the others, or a single bad
     * teardown would hold resources open for the life of the worker.
     */
    public function test_every_teardown_runs_even_when_one_throws(): void
    {
        $container = new Container();
        $container->freeze();

        $ran = false;
        $scope = $container->enterRequest();
        $scope->onClose(static function () use (&$ran): void {
            $ran = true;
        });
        $scope->onClose(static function (): void {
            throw new \RuntimeException('release failed');
        });

        try {
            $scope->close();
            self::fail('close() should rethrow the failing release.');
        } catch (\RuntimeException $e) {
            self::assertSame('release failed', $e->getMessage());
        }

        self::assertTrue($ran);
    }
}
