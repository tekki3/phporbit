<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Session;

use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Session\Session;
use PhpOrbit\Session\SessionMiddleware;
use PhpOrbit\Tests\Support\ArraySessionStore;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class SessionMiddlewareTest extends TestCase
{
    private ArraySessionStore $store;

    protected function setUp(): void
    {
        $this->store = new ArraySessionStore();
    }

    /**
     * An anonymous visitor who stores nothing must not leave a session behind,
     * or a crawler would fill the store with empty files.
     */
    public function test_a_read_only_request_creates_no_session(): void
    {
        $app = $this->application(static fn (): Response => Response::text('ok'));

        $response = $app->handle(Requests::get('/'));

        self::assertSame(0, $this->store->count());
        self::assertSame([], $response->headers->all('Set-Cookie'));
    }

    public function test_writing_persists_the_session_and_sets_a_cookie(): void
    {
        $app = $this->application(static function (ServerRequest $r, RequestScope $scope): Response {
            $scope->get(Session::class)->set('user', 'ada');

            return Response::text('ok');
        });

        $response = $app->handle(Requests::get('/'));

        self::assertSame(1, $this->store->count());
        self::assertMatchesRegularExpression(
            '/orbit_session=[a-f0-9]{64}.*HttpOnly.*SameSite=Lax/',
            (string) $response->headers->first('Set-Cookie'),
        );
    }

    public function test_a_returning_visitor_sees_their_data(): void
    {
        $id = Session::generateId();
        $this->store->write($id, ['user' => 'ada'], 3600);

        $app = $this->application(static fn (ServerRequest $r, RequestScope $scope): Response => Response::text(
            $scope->get(Session::class)->get('user') ?? 'anonymous',
        ));

        $response = $app->handle(Requests::of(Method::Get, '/', cookies: ['orbit_session' => $id]));

        self::assertSame('ada', $response->body);
    }

    /**
     * Session fixation: a cookie naming a session the store does not have must
     * not cause that id to be adopted, or an attacker could pick the id.
     */
    public function test_an_unknown_cookie_id_is_not_adopted(): void
    {
        $planted = Session::generateId();

        $app = $this->application(static function (ServerRequest $r, RequestScope $scope): Response {
            $scope->get(Session::class)->set('user', 'ada');

            return Response::text('ok');
        });

        $response = $app->handle(Requests::of(Method::Get, '/', cookies: ['orbit_session' => $planted]));

        self::assertFalse($this->store->has($planted), 'the attacker-chosen id must not become real');
        self::assertStringNotContainsString($planted, (string) $response->headers->first('Set-Cookie'));
    }

    /**
     * A malformed id must never reach the store, where it would be used to
     * build a filesystem path.
     */
    public function test_a_malformed_cookie_id_is_ignored(): void
    {
        $app = $this->application(static fn (ServerRequest $r, RequestScope $scope): Response => Response::text(
            $scope->get(Session::class)->get('user') ?? 'anonymous',
        ));

        $response = $app->handle(Requests::of(
            Method::Get,
            '/',
            cookies: ['orbit_session' => '../../etc/passwd'],
        ));

        self::assertSame('anonymous', $response->body);
    }

    public function test_regeneration_removes_the_old_session(): void
    {
        $original = Session::generateId();
        $this->store->write($original, ['user' => 'ada'], 3600);

        $app = $this->application(static function (ServerRequest $r, RequestScope $scope): Response {
            $scope->get(Session::class)->regenerate();

            return Response::text('ok');
        });

        $app->handle(Requests::of(Method::Get, '/', cookies: ['orbit_session' => $original]));

        self::assertFalse($this->store->has($original), 'the pre-regeneration id must stop working');
        self::assertSame(1, $this->store->count());
    }

    public function test_destroying_clears_the_store_and_expires_the_cookie(): void
    {
        $id = Session::generateId();
        $this->store->write($id, ['user' => 'ada'], 3600);

        $app = $this->application(static function (ServerRequest $r, RequestScope $scope): Response {
            $scope->get(Session::class)->destroy();

            return Response::text('bye');
        });

        $response = $app->handle(Requests::of(Method::Get, '/', cookies: ['orbit_session' => $id]));

        self::assertFalse($this->store->has($id));
        self::assertStringContainsString('Max-Age=0', (string) $response->headers->first('Set-Cookie'));
    }

    /**
     * @param \Closure(ServerRequest, RequestScope): Response $handler
     */
    private function application(\Closure $handler): Application
    {
        $store = $this->store;

        return Application::boot(static function (Blueprint $app) use ($store, $handler): void {
            $app->middleware(new SessionMiddleware($store));

            $app->routes->get('/', $handler);
        });
    }
}
