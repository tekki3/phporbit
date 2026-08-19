<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Security;

use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Security\CsrfMiddleware;
use PhpOrbit\Session\Session;
use PhpOrbit\Session\SessionMiddleware;
use PhpOrbit\Tests\Support\ArraySessionStore;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function test_a_token_is_minted_once_and_reused(): void
    {
        $session = Session::started();

        $first = Csrf::token($session);
        $second = Csrf::token($session);

        self::assertSame($first, $second);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
    }

    public function test_validation_accepts_the_session_token_only(): void
    {
        $session = Session::started();
        $token = Csrf::token($session);

        self::assertTrue(Csrf::isValid($session, $token));
        self::assertFalse(Csrf::isValid($session, strrev($token)));
        self::assertFalse(Csrf::isValid($session, null));
        self::assertFalse(Csrf::isValid($session, ''));
    }

    public function test_a_session_without_a_token_validates_nothing(): void
    {
        self::assertFalse(Csrf::isValid(Session::started(), str_repeat('a', 64)));
    }

    public function test_rotate_issues_a_new_token(): void
    {
        $session = Session::started();
        $original = Csrf::token($session);

        Csrf::rotate($session);

        self::assertNotSame($original, Csrf::token($session));
    }

    public function test_the_hidden_field_carries_the_token(): void
    {
        $session = Session::started();

        self::assertStringContainsString(Csrf::token($session), Csrf::field($session));
        self::assertStringContainsString('name="_token"', Csrf::field($session));
    }

    // --- middleware behaviour -------------------------------------------------

    public function test_safe_methods_pass_without_a_token(): void
    {
        $app = $this->application();

        self::assertSame(Status::Ok, $app->handle(Requests::get('/thing'))->status);
    }

    public function test_a_post_without_a_token_is_rejected(): void
    {
        $app = $this->application();

        $response = $app->handle(Requests::form('/thing', ['title' => 'x']));

        self::assertSame(Status::Forbidden, $response->status);
    }

    public function test_a_post_with_a_wrong_token_is_rejected(): void
    {
        $app = $this->application();
        [$cookie] = $this->establishSession($app);

        $response = $app->handle(Requests::form(
            '/thing',
            ['_token' => str_repeat('0', 64)],
            ['orbit_session' => $cookie],
        ));

        self::assertSame(Status::Forbidden, $response->status);
    }

    public function test_a_post_with_the_right_token_is_accepted(): void
    {
        $app = $this->application();
        [$cookie, $token] = $this->establishSession($app);

        $response = $app->handle(Requests::form(
            '/thing',
            ['_token' => $token],
            ['orbit_session' => $cookie],
        ));

        self::assertSame(Status::Ok, $response->status);
        self::assertSame('created', $response->body);
    }

    /**
     * fetch/XHR callers cannot always add a form field.
     */
    public function test_the_token_may_arrive_as_a_header(): void
    {
        $app = $this->application();
        [$cookie, $token] = $this->establishSession($app);

        $response = $app->handle(Requests::of(
            Method::Post,
            '/thing',
            [Csrf::HEADER_NAME => $token],
            cookies: ['orbit_session' => $cookie],
        ));

        self::assertSame(Status::Ok, $response->status);
    }

    /**
     * A token from one visitor must not authorise another's request.
     */
    public function test_a_token_from_another_session_is_rejected(): void
    {
        $app = $this->application();
        [$cookieA] = $this->establishSession($app);
        [, $tokenB] = $this->establishSession($app);

        $response = $app->handle(Requests::form(
            '/thing',
            ['_token' => $tokenB],
            ['orbit_session' => $cookieA],
        ));

        self::assertSame(Status::Forbidden, $response->status);
    }

    public function test_an_exempt_route_skips_the_check(): void
    {
        $app = $this->application();

        self::assertSame(Status::Ok, $app->handle(Requests::form('/webhook', []))->status);
    }

    private function application(): Application
    {
        $store = new ArraySessionStore();

        return Application::boot(static function (Blueprint $app) use ($store): void {
            $app->middleware(new SessionMiddleware($store), new CsrfMiddleware());

            $app->routes->get('/thing', static fn (
                ServerRequest $r,
                \PhpOrbit\Container\RequestScope $scope,
            ): Response => Response::text(Csrf::token($scope->get(Session::class))));

            $app->routes->post('/thing', static fn (): Response => Response::text('created'));

            $app->routes->add(
                Method::Post,
                '/webhook',
                static fn (): Response => Response::text('accepted'),
                csrfExempt: true,
            );
        });
    }

    /**
     * Performs a GET to obtain a session cookie and its CSRF token.
     *
     * @return array{0: string, 1: string} cookie value, token
     */
    private function establishSession(Application $app): array
    {
        $response = $app->handle(Requests::get('/thing'));

        $cookie = null;
        foreach ($response->headers->all('Set-Cookie') as $header) {
            if (preg_match('/orbit_session=([a-f0-9]{64})/', $header, $m) === 1) {
                $cookie = $m[1];
            }
        }

        self::assertNotNull($cookie, 'the GET should have started a session');

        return [$cookie, $response->body];
    }
}
