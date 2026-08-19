<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Container;

use PhpOrbit\Container\Container;
use PhpOrbit\Container\Exception\CannotAutowire;
use PhpOrbit\Container\Exception\ServiceNotFound;
use PhpOrbit\Tests\Support\Counter;
use PhpOrbit\Tests\Support\NeedsCounter;
use PhpOrbit\Tests\Support\NeedsScalar;
use PhpOrbit\Tests\Support\SelfReferencing;
use PHPUnit\Framework\TestCase;

final class AutowiringTest extends TestCase
{
    public function test_it_constructs_an_unregistered_class(): void
    {
        $scope = $this->scope();

        self::assertInstanceOf(Counter::class, $scope->get(Counter::class));
    }

    public function test_it_resolves_constructor_dependencies(): void
    {
        $scope = $this->scope();

        $service = $scope->get(NeedsCounter::class);

        // Both resolutions share one scope, so they share the dependency too.
        self::assertSame($scope->get(Counter::class), $service->counter);
    }

    public function test_a_registered_singleton_wins_over_autowiring(): void
    {
        $container = new Container();
        $shared = new Counter();
        $container->singleton(Counter::class, static fn (): Counter => $shared);
        $container->freeze();

        self::assertSame($shared, $container->enterRequest()->get(Counter::class));
    }

    /**
     * Autowiring must not reach the process-lifetime container: an instance
     * cached there would be shared by every later request in a worker.
     */
    public function test_the_boot_container_still_refuses_unregistered_classes(): void
    {
        $container = new Container();
        $container->freeze();

        $this->expectException(ServiceNotFound::class);

        $container->get(Counter::class);
    }

    public function test_a_scalar_dependency_is_an_error_rather_than_a_guess(): void
    {
        $this->expectException(CannotAutowire::class);
        $this->expectExceptionMessageMatches('/no class type and no default/');

        $this->scope()->get(NeedsScalar::class);
    }

    public function test_a_defaulted_scalar_is_accepted(): void
    {
        self::assertSame(8080, $this->scope()->get(\PhpOrbit\Tests\Support\HasDefault::class)->port);
    }

    public function test_an_interface_must_be_registered(): void
    {
        $this->expectException(CannotAutowire::class);
        $this->expectExceptionMessageMatches('/interface or abstract/');

        $this->scope()->get(\PhpOrbit\Session\SessionStore::class);
    }

    public function test_a_dependency_cycle_is_reported_rather_than_recursing_forever(): void
    {
        $this->expectException(CannotAutowire::class);
        $this->expectExceptionMessageMatches('/Circular dependency/');

        $this->scope()->get(SelfReferencing::class);
    }

    private function scope(): \PhpOrbit\Container\RequestScope
    {
        $container = new Container();
        $container->freeze();

        return $container->enterRequest();
    }
}
