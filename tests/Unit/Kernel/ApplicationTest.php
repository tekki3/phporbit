<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Kernel;

use Closure;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Middleware\Middleware;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApplicationTest extends TestCase
{
    public function test_it_dispatches_to_a_handler(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->get('/greet/{name}', static fn (ServerRequest $r): Response => Response::text(
                'hello ' . $r->attribute('name'),
            ));
        });

        $response = $app->handle(Requests::get('/greet/ada'));

        self::assertSame(Status::Ok, $response->status);
        self::assertSame('hello ada', $response->body);
    }

    public function test_an_unmatched_path_is_a_404(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->get('/', static fn (): Response => Response::text('root'));
        });

        self::assertSame(Status::NotFound, $app->handle(Requests::get('/nope'))->status);
    }

    public function test_a_wrong_method_is_a_405_with_an_allow_header(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->get('/thing', static fn (): Response => Response::text('ok'));
        });

        $response = $app->handle(Requests::post('/thing'));

        self::assertSame(Status::MethodNotAllowed, $response->status);
        self::assertSame('GET', $response->headers->first('Allow'));
    }

    public function test_head_returns_the_get_headers_without_a_body(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->get('/page', static fn (): Response => Response::html('<p>content</p>'));
        });

        $response = $app->handle(Requests::of(Method::Head, '/page'));

        self::assertSame(Status::Ok, $response->status);
        self::assertSame('', $response->body);
        self::assertSame('text/html; charset=utf-8', $response->headers->first('Content-Type'));
    }

    /**
     * An exception message can contain paths, SQL or credentials, so the
     * production response must reveal none of it.
     */
    public function test_a_handler_failure_is_opaque_outside_debug_mode(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->get('/boom', static function (): Response {
                throw new RuntimeException('connection string: user:hunter2@db');
            });
        }, debug: false);

        $response = $app->handle(Requests::get('/boom'));

        self::assertSame(Status::InternalServerError, $response->status);
        self::assertSame('Internal Server Error', $response->body);
        self::assertStringNotContainsString('hunter2', $response->body);
    }

    public function test_debug_mode_shows_the_failure(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->get('/boom', static function (): Response {
                throw new RuntimeException('the actual cause');
            });
        }, debug: true);

        $response = $app->handle(Requests::get('/boom'));

        self::assertSame(Status::InternalServerError, $response->status);
        self::assertStringContainsString('the actual cause', $response->body);
    }

    public function test_security_headers_are_present_on_every_response(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->get('/', static fn (): Response => Response::text('ok'));
        });

        foreach (['/', '/missing'] as $path) {
            $headers = $app->handle(Requests::get($path))->headers;

            self::assertSame('nosniff', $headers->first('X-Content-Type-Options'), $path);
            self::assertSame('DENY', $headers->first('X-Frame-Options'), $path);
        }
    }

    public function test_the_container_is_frozen_once_boot_returns(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->get('/', static fn (): Response => Response::text('ok'));

            self::assertFalse($app->container->isFrozen(), 'the container is open during boot');
        });

        self::assertTrue($app->container()->isFrozen());
    }

    public function test_global_middleware_wraps_the_handler(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->middleware(self::tagging('outer'), self::tagging('inner'));

            $app->routes->get('/', static fn (): Response => Response::text('body'));
        });

        // Registration order is outermost first, so the outer layer's header
        // is applied last and both are present.
        $response = $app->handle(Requests::get('/'));

        self::assertSame('yes', $response->headers->first('X-Outer'));
        self::assertSame('yes', $response->headers->first('X-Inner'));
    }

    /**
     * Middleware must see requests that matched nothing, or logging and
     * auditing would silently miss every 404.
     */
    public function test_global_middleware_runs_for_unmatched_routes(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->middleware(self::tagging('outer'));

            $app->routes->get('/', static fn (): Response => Response::text('root'));
        });

        $response = $app->handle(Requests::get('/missing'));

        self::assertSame(Status::NotFound, $response->status);
        self::assertSame('yes', $response->headers->first('X-Outer'));
    }

    public function test_route_middleware_runs_only_for_its_own_route(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->get('/guarded', static fn (): Response => Response::text('ok'), middleware: [
                self::tagging('route'),
            ]);
            $app->routes->get('/plain', static fn (): Response => Response::text('ok'));
        });

        self::assertSame('yes', $app->handle(Requests::get('/guarded'))->headers->first('X-Route'));
        self::assertNull($app->handle(Requests::get('/plain'))->headers->first('X-Route'));
    }

    public function test_middleware_can_short_circuit_the_handler(): void
    {
        $reached = false;

        $app = Application::boot(static function (Blueprint $app) use (&$reached): void {
            $app->middleware(new class implements Middleware {
                public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
                {
                    return Response::text('blocked', Status::Forbidden);
                }
            });

            $app->routes->get('/', static function () use (&$reached): Response {
                $reached = true;

                return Response::text('handler');
            });
        });

        $response = $app->handle(Requests::get('/'));

        self::assertSame(Status::Forbidden, $response->status);
        self::assertFalse($reached, 'the handler must not run when middleware returns early');
    }

    public function test_a_controller_class_is_resolved_and_invoked(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->get('/ctrl/{name}', GreetingController::class);
        });

        self::assertSame('hi ada', $app->handle(Requests::get('/ctrl/ada'))->body);
    }

    /**
     * Middleware that tags the response so ordering is observable.
     */
    private static function tagging(string $name): Middleware
    {
        return new class ($name) implements Middleware {
            public function __construct(private readonly string $name)
            {
            }

            public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
            {
                return $next($request)->withHeader('X-' . ucfirst($this->name), 'yes');
            }
        };
    }
}
