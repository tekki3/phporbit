<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Kernel;

use InvalidArgumentException;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Middleware\Middleware;
use PhpOrbit\Routing\RouteCollection;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class BlueprintTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/orbit-routes-' . bin2hex(random_bytes(6));

        mkdir($this->directory, 0o750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function test_it_loads_routes_from_a_file(): void
    {
        $path = $this->write(<<<'PHP'
            <?php
            use PhpOrbit\Http\Response;
            use PhpOrbit\Routing\RouteCollection;
            return static function (RouteCollection $routes, bool $debug): void {
                $routes->get('/from-file', static fn (): Response => Response::text('loaded'), 'from-file');
            };
            PHP);

        $app = Application::boot(static function (Blueprint $app) use ($path): void {
            $app->loadRoutes($path);
        });

        self::assertSame('loaded', $app->handle(Requests::get('/from-file'))->body);
        self::assertTrue($app->router()->hasName('from-file'));
    }

    /**
     * The debug flag reaches the file, so a route can be registered only when
     * debugging without the file having to read the environment itself.
     */
    public function test_the_debug_flag_is_passed_through(): void
    {
        $path = $this->write(<<<'PHP'
            <?php
            use PhpOrbit\Http\Response;
            use PhpOrbit\Routing\RouteCollection;
            return static function (RouteCollection $routes, bool $debug): void {
                if ($debug) {
                    $routes->get('/debug-only', static fn (): Response => Response::text('yes'), 'debug-only');
                }
            };
            PHP);

        $load = static function (Blueprint $app) use ($path): void {
            $app->loadRoutes($path);
        };

        $off = Application::boot($load, debug: false);
        $on = Application::boot($load, debug: true);

        self::assertFalse($off->router()->hasName('debug-only'));
        self::assertTrue($on->router()->hasName('debug-only'));
    }

    /**
     * A routes file that forgets to return would otherwise fail with "value of
     * type null is not callable", which says nothing about the real mistake.
     */
    public function test_a_file_that_returns_nothing_is_reported_clearly(): void
    {
        $path = $this->write("<?php\n\$routes = [];\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must return a closure/');

        Application::boot(static function (Blueprint $app) use ($path): void {
            $app->loadRoutes($path);
        });
    }

    public function test_a_missing_routes_file_is_reported(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No routes file at/');

        Application::boot(function (Blueprint $app): void {
            $app->loadRoutes($this->directory . '/absent.php');
        });
    }

    /**
     * Routes loaded from a file still land during boot, so the container is
     * frozen and the table compiled exactly as if they were declared inline.
     */
    public function test_routes_from_a_file_are_still_compiled_at_boot(): void
    {
        $path = $this->write(<<<'PHP'
            <?php
            use PhpOrbit\Http\Response;
            use PhpOrbit\Routing\RouteCollection;
            return static function (RouteCollection $routes, bool $debug): void {
                $routes->get('/x', static fn (): Response => Response::text('x'));
            };
            PHP);

        $app = Application::boot(static function (Blueprint $app) use ($path): void {
            $app->loadRoutes($path);
        });

        self::assertTrue($app->container()->isFrozen());
        self::assertCount(1, $app->router()->routes());
    }

    // --- withMiddleware ------------------------------------------------------

    public function test_with_middleware_applies_to_every_route_in_the_block(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->withMiddleware([self::blocking()], static function (RouteCollection $routes): void {
                $routes->get('/one', static fn (): Response => Response::text('one'));
                $routes->get('/two', static fn (): Response => Response::text('two'));
            });
        });

        self::assertSame(Status::Forbidden, $app->handle(Requests::get('/one'))->status);
        self::assertSame(Status::Forbidden, $app->handle(Requests::get('/two'))->status);
    }

    /**
     * The guard must not leak onto routes declared after the block — that
     * would be a silent, and very confusing, lockout.
     */
    public function test_with_middleware_does_not_leak_past_the_block(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->withMiddleware([self::blocking()], static function (RouteCollection $routes): void {
                $routes->get('/guarded', static fn (): Response => Response::text('guarded'));
            });

            $app->routes->get('/open', static fn (): Response => Response::text('open'));
        });

        self::assertSame(Status::Forbidden, $app->handle(Requests::get('/guarded'))->status);
        self::assertSame('open', $app->handle(Requests::get('/open'))->body);
    }

    /**
     * Paths are unchanged: this is a middleware grouping, not a prefix.
     */
    public function test_with_middleware_does_not_alter_paths(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->withMiddleware([], static function (RouteCollection $routes): void {
                $routes->get('/plain', static fn (): Response => Response::text('plain'), 'plain');
            });
        });

        self::assertSame('/plain', $app->router()->urlFor('plain'));
    }

    private static function blocking(): Middleware
    {
        return new class implements Middleware {
            public function process(ServerRequest $request, RequestScope $scope, \Closure $next): Response
            {
                return Response::text('blocked', Status::Forbidden);
            }
        };
    }

    private function write(string $contents): string
    {
        $path = $this->directory . '/routes-' . bin2hex(random_bytes(4)) . '.php';

        file_put_contents($path, $contents);

        return $path;
    }
}
