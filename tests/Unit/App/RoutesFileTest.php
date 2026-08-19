<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\App;

use Closure;
use PhpOrbit\Auth\RequireAuthentication;
use PhpOrbit\Http\Method;
use PhpOrbit\Routing\Route;
use PhpOrbit\Routing\RouteCollection;
use PhpOrbit\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * Checks the application's real routes file.
 *
 * It loads `app/routes.php` into a bare collection rather than booting the
 * whole application, so the assertions are about the routing declarations and
 * nothing else — no database, no storage directories, no environment.
 *
 * The point is the guard coverage. Which routes require a signed-in user is
 * the kind of thing that quietly regresses when someone adds a route next to
 * an existing one and misses that it sits outside the guarded block.
 */
final class RoutesFileTest extends TestCase
{
    public function test_the_expected_routes_are_declared(): void
    {
        $names = [];

        foreach ($this->router()->routes() as $route) {
            if ($route->name !== null) {
                $names[] = $route->name;
            }
        }

        sort($names);

        self::assertSame([
            'avatar',
            'avatar.store',
            'contact',
            'contact.submit',
            'health',
            'hello',
            'login',
            'login.attempt',
            'logout',
            'notes.create',
            'notes.delete',
            'notes.index',
            'self-check',
        ], $names);
    }

    /**
     * Everything that changes state on someone's behalf must be behind the
     * authentication guard.
     */
    public function test_write_routes_require_authentication(): void
    {
        $guarded = ['notes.create', 'notes.delete', 'avatar', 'avatar.store'];

        foreach ($guarded as $name) {
            self::assertTrue(
                $this->hasAuthGuard($this->routeNamed($name)),
                sprintf('Route "%s" is missing RequireAuthentication.', $name),
            );
        }
    }

    /**
     * The counterpart: a guard on the sign-in page would lock everyone out
     * permanently, and one on the public pages would break the demo.
     */
    public function test_public_routes_are_not_guarded(): void
    {
        foreach (['self-check', 'notes.index', 'hello', 'health', 'login', 'login.attempt'] as $name) {
            self::assertFalse(
                $this->hasAuthGuard($this->routeNamed($name)),
                sprintf('Route "%s" should be reachable without signing in.', $name),
            );
        }
    }

    /**
     * Reading notes is public; writing them is not. The two share a path, so
     * this is exactly the pair most likely to be confused.
     */
    public function test_notes_is_readable_but_not_writable_by_a_guest(): void
    {
        self::assertSame(Method::Get, $this->routeNamed('notes.index')->method);
        self::assertSame(Method::Post, $this->routeNamed('notes.create')->method);

        self::assertFalse($this->hasAuthGuard($this->routeNamed('notes.index')));
        self::assertTrue($this->hasAuthGuard($this->routeNamed('notes.create')));
    }

    /**
     * The failure demo must not exist in production, where it would be a
     * reachable endpoint that throws.
     */
    public function test_the_boom_route_exists_only_in_debug(): void
    {
        self::assertFalse($this->router(debug: false)->hasName('boom'));
        self::assertTrue($this->router(debug: true)->hasName('boom'));
    }

    /**
     * Names are what `urlFor()` builds links from, so a parameterised route
     * has to actually accept its parameters.
     */
    public function test_named_routes_can_generate_urls(): void
    {
        $router = $this->router();

        self::assertSame('/hello/world', $router->urlFor('hello', ['name' => 'world']));
        self::assertSame('/notes/42/delete', $router->urlFor('notes.delete', ['id' => 42]));
        self::assertSame('/', $router->urlFor('self-check'));
    }

    private function hasAuthGuard(Route $route): bool
    {
        foreach ($route->middleware as $layer) {
            if ($layer instanceof RequireAuthentication) {
                return true;
            }
        }

        return false;
    }

    private function routeNamed(string $name): Route
    {
        foreach ($this->router()->routes() as $route) {
            if ($route->name === $name) {
                return $route;
            }
        }

        self::fail(sprintf('No route named "%s".', $name));
    }

    private function router(bool $debug = false): Router
    {
        /** @var mixed $define */
        $define = require dirname(__DIR__, 3) . '/app/routes.php';

        self::assertInstanceOf(Closure::class, $define);

        $routes = new RouteCollection();
        $define($routes, $debug);

        return $routes->compile();
    }
}
