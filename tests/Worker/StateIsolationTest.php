<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Worker;

use PhpOrbit\Container\Container;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Session\Session;
use PhpOrbit\Session\SessionMiddleware;
use PhpOrbit\Tests\Support\ArraySessionStore;
use PhpOrbit\Tests\Support\Counter;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Serves many requests from one booted application, as a worker does.
 *
 * These tests exist because state-leak bugs are invisible under a per-request
 * SAPI: Apache and FPM destroy the process between requests, so a leak there
 * has nothing to leak into. Under FrankenPHP or the built-in server the same
 * bug serves one user's data to the next.
 *
 * Every test here boots once and handles at least twice.
 */
final class StateIsolationTest extends TestCase
{
    public function test_request_attributes_do_not_survive_into_the_next_request(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->get('/echo/{value}', static fn (ServerRequest $r): Response => Response::text(
                (string) $r->attribute('value'),
            ));
        });

        self::assertSame('first', $app->handle(Requests::get('/echo/first'))->body);
        self::assertSame('second', $app->handle(Requests::get('/echo/second'))->body);
    }

    /**
     * The canonical leak: a stateful service that should be per-request.
     *
     * If the scope were shared, the second request would see a count of 2.
     */
    public function test_a_scoped_service_starts_fresh_for_each_request(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->container->scoped(Counter::class, static fn (): Counter => new Counter());

            $app->routes->get('/count', static fn (ServerRequest $r, RequestScope $scope): Response => Response::text(
                (string) $scope->get(Counter::class)->increment(),
            ));
        });

        self::assertSame('1', $app->handle(Requests::get('/count'))->body);
        self::assertSame('1', $app->handle(Requests::get('/count'))->body);
        self::assertSame('1', $app->handle(Requests::get('/count'))->body);
    }

    /**
     * The counterpart: a singleton is genuinely shared, which is what makes
     * the scoped case above meaningful rather than vacuous.
     */
    public function test_a_singleton_persists_across_requests(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->container->singleton(Counter::class, static fn (): Counter => new Counter());

            $app->routes->get('/count', static fn (ServerRequest $r, RequestScope $scope): Response => Response::text(
                (string) $scope->get(Counter::class)->increment(),
            ));
        });

        self::assertSame('1', $app->handle(Requests::get('/count'))->body);
        self::assertSame('2', $app->handle(Requests::get('/count'))->body);
    }

    /**
     * An autowired class must never be cached at container level, or the
     * instance would be shared by every later request in the process.
     */
    public function test_an_autowired_service_is_rebuilt_for_each_request(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->get('/auto', static fn (ServerRequest $r, RequestScope $scope): Response => Response::text(
                (string) $scope->get(Counter::class)->increment(),
            ));
        });

        self::assertSame('1', $app->handle(Requests::get('/auto'))->body);
        self::assertSame('1', $app->handle(Requests::get('/auto'))->body);
    }

    /**
     * One visitor's session must never be visible to another. The two requests
     * below carry different cookies through one booted application.
     */
    public function test_sessions_do_not_bleed_between_visitors(): void
    {
        $store = new ArraySessionStore();

        $app = Application::boot(static function (Blueprint $app) use ($store): void {
            $app->middleware(new SessionMiddleware($store));

            $app->routes->get('/whoami', static function (ServerRequest $r, RequestScope $scope): Response {
                $session = $scope->get(Session::class);
                $name = $r->uri->queryParam('name');

                if ($name !== null) {
                    $session->set('name', $name);
                }

                return Response::text($session->get('name') ?? 'anonymous');
            });
        });

        $alice = $app->handle(Requests::get('/whoami?name=alice'));
        $aliceCookie = $this->sessionCookie($alice);

        $bob = $app->handle(Requests::get('/whoami?name=bob'));
        $bobCookie = $this->sessionCookie($bob);

        self::assertNotSame($aliceCookie, $bobCookie, 'each visitor gets a distinct session id');

        // Replay both cookies against the same long-lived application.
        self::assertSame('alice', $app->handle(
            Requests::of(\PhpOrbit\Http\Method::Get, '/whoami', cookies: ['orbit_session' => $aliceCookie]),
        )->body);

        self::assertSame('bob', $app->handle(
            Requests::of(\PhpOrbit\Http\Method::Get, '/whoami', cookies: ['orbit_session' => $bobCookie]),
        )->body);

        // A visitor with no cookie sees neither.
        self::assertSame('anonymous', $app->handle(Requests::get('/whoami'))->body);
    }

    public function test_the_request_scope_is_closed_after_every_request(): void
    {
        $closed = [];

        $app = Application::boot(static function (Blueprint $app) use (&$closed): void {
            $app->routes->get('/work', static function (ServerRequest $r, RequestScope $scope) use (&$closed): Response {
                $scope->onClose(static function () use (&$closed): void {
                    $closed[] = true;
                });

                return Response::text('ok');
            });
        });

        $app->handle(Requests::get('/work'));
        $app->handle(Requests::get('/work'));

        self::assertCount(2, $closed);
    }

    /**
     * Resources must be released on the error path too. A handler that throws
     * mid-transaction would otherwise hold it open for the life of the worker.
     */
    public function test_teardown_runs_even_when_the_handler_throws(): void
    {
        $released = 0;

        $app = Application::boot(static function (Blueprint $app) use (&$released): void {
            $app->routes->get('/boom', static function (ServerRequest $r, RequestScope $scope) use (&$released): Response {
                $scope->onClose(static function () use (&$released): void {
                    $released++;
                });

                throw new RuntimeException('handler failed');
            });
        });

        self::assertSame(Status::InternalServerError, $app->handle(Requests::get('/boom'))->status);
        self::assertSame(Status::InternalServerError, $app->handle(Requests::get('/boom'))->status);
        self::assertSame(2, $released);
    }

    /**
     * A failure must not poison the worker: the next request is served
     * normally.
     */
    public function test_the_worker_recovers_after_a_failing_request(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->get('/boom', static function (): Response {
                throw new RuntimeException('handler failed');
            });
            $app->routes->get('/fine', static fn (): Response => Response::text('fine'));
        });

        $app->handle(Requests::get('/boom'));
        $response = $app->handle(Requests::get('/fine'));

        self::assertSame(Status::Ok, $response->status);
        self::assertSame('fine', $response->body);
    }

    /**
     * Two scopes opened from the same container must never share instances,
     * which is what would happen if the container cached scoped services.
     */
    public function test_concurrent_scopes_are_independent(): void
    {
        $container = new Container();
        $container->scoped(Counter::class, static fn (): Counter => new Counter());
        $container->freeze();

        $first = $container->enterRequest();
        $second = $container->enterRequest();

        $first->get(Counter::class)->increment();
        $first->get(Counter::class)->increment();

        self::assertSame(2, $first->get(Counter::class)->count());
        self::assertSame(0, $second->get(Counter::class)->count());
    }

    /**
     * Memory must not grow request over request. A slow leak is invisible in
     * a handful of requests but fatal over a worker's lifetime.
     */
    public function test_memory_does_not_grow_across_many_requests(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->container->scoped(Counter::class, static fn (): Counter => new Counter());

            $app->routes->get('/count/{n}', static fn (ServerRequest $r, RequestScope $scope): Response => Response::text(
                (string) $scope->get(Counter::class)->increment() . $r->attribute('n'),
            ));
        });

        // Warm up so first-call allocations are not counted as growth.
        for ($i = 0; $i < 200; $i++) {
            $app->handle(Requests::get('/count/' . $i));
        }

        gc_collect_cycles();
        $baseline = memory_get_usage();

        for ($i = 0; $i < 2000; $i++) {
            $app->handle(Requests::get('/count/' . $i));
        }

        gc_collect_cycles();
        $growth = memory_get_usage() - $baseline;

        self::assertLessThan(
            256 * 1024,
            $growth,
            sprintf('memory grew by %d bytes over 2000 requests, which suggests a leak', $growth),
        );
    }

    /**
     * Pulls the session id out of a response's Set-Cookie header.
     */
    private function sessionCookie(Response $response): string
    {
        foreach ($response->headers->all('Set-Cookie') as $header) {
            if (preg_match('/orbit_session=([a-f0-9]{64})/', $header, $m) === 1) {
                return $m[1];
            }
        }

        self::fail('the response did not set a session cookie');
    }
}
