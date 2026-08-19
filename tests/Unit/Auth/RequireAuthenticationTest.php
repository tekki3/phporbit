<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Auth;

use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\PasswordHasher;
use PhpOrbit\Auth\RequireAuthentication;
use PhpOrbit\Auth\UserProvider;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Session\Session;
use PhpOrbit\Session\SessionMiddleware;
use PhpOrbit\Tests\Support\ArraySessionStore;
use PhpOrbit\Tests\Support\ArrayUserProvider;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class RequireAuthenticationTest extends TestCase
{
    private ArraySessionStore $sessions;

    private ArrayUserProvider $users;

    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->sessions = new ArraySessionStore();
        $this->hasher = new PasswordHasher();
        $this->users = new ArrayUserProvider();
        $this->users->add('1', 'ada@example.test', $this->hasher->hash('correct-horse'));
    }

    public function test_a_guest_is_redirected_to_the_login_page(): void
    {
        $response = $this->application()->handle(Requests::get('/private'));

        self::assertSame(Status::Found, $response->status);
        self::assertSame('/login', $response->headers->first('Location'));
    }

    public function test_a_signed_in_user_reaches_the_handler(): void
    {
        $app = $this->application();
        $cookie = $this->signIn($app);

        $response = $app->handle(Requests::of(
            Method::Get,
            '/private',
            cookies: ['orbit_session' => $cookie],
        ));

        self::assertSame(Status::Ok, $response->status);
        self::assertSame('secret', $response->body);
    }

    /**
     * Redirecting an API client to a login page turns an auth failure into a
     * 200 containing HTML, which is far harder to debug than a 401.
     */
    public function test_a_non_browser_client_gets_401_rather_than_a_redirect(): void
    {
        $response = $this->application()->handle(Requests::of(
            Method::Get,
            '/private',
            ['Accept' => 'application/json'],
        ));

        self::assertSame(Status::Unauthorized, $response->status);
    }

    public function test_the_intended_destination_is_remembered_for_a_get(): void
    {
        $app = $this->application();

        $response = $app->handle(Requests::get('/private'));

        $id = $this->sessionCookie($response);

        self::assertSame('/private', $this->sessions->read($id)[RequireAuthentication::INTENDED_KEY] ?? null);
    }

    /**
     * Replaying a POST after login would repeat a side effect the user never
     * confirmed, so only safe methods are remembered.
     */
    public function test_a_post_destination_is_not_remembered(): void
    {
        $app = $this->application();

        $app->handle(Requests::form('/private', []));

        // Whatever sessions exist, none of them may name the POST as somewhere
        // to return to — replaying it after login would repeat a side effect
        // the user never confirmed.
        foreach ($this->sessions->all() as $data) {
            self::assertArrayNotHasKey(RequireAuthentication::INTENDED_KEY, $data);
        }

        self::assertSame(
            0,
            $this->sessions->count(),
            'a guest POST should not even start a session',
        );
    }

    private function application(): Application
    {
        $sessions = $this->sessions;
        $users = $this->users;
        $hasher = $this->hasher;

        return Application::boot(static function (Blueprint $app) use ($sessions, $users, $hasher): void {
            $app->container->singleton(UserProvider::class, static fn (): UserProvider => $users);
            $app->container->singleton(PasswordHasher::class, static fn (): PasswordHasher => $hasher);

            $app->middleware(new SessionMiddleware($sessions));

            $app->routes->get(
                '/private',
                static fn (): Response => Response::text('secret'),
                middleware: [new RequireAuthentication()],
            );

            $app->routes->add(
                Method::Post,
                '/private',
                static fn (): Response => Response::text('secret'),
                csrfExempt: true,
                middleware: [new RequireAuthentication()],
            );

            $app->routes->post('/sign-in', static function (
                ServerRequest $r,
                RequestScope $scope,
            ): Response {
                $scope->get(Authenticator::class)->attempt('ada@example.test', 'correct-horse');

                return Response::text('signed in');
            }, csrfExempt: true);
        });
    }

    private function signIn(Application $app): string
    {
        // The sign-in route is CSRF-exempt purely so this test can drive it
        // without first fetching a token.
        return $this->sessionCookie($app->handle(Requests::form('/sign-in', [])));
    }

    private function sessionCookie(Response $response): string
    {
        foreach ($response->headers->all('Set-Cookie') as $header) {
            if (preg_match('/orbit_session=([a-f0-9]{64})/', $header, $m) === 1) {
                return $m[1];
            }
        }

        self::fail('no session cookie was set');
    }
}
