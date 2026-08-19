<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Worker;

use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\PasswordHasher;
use PhpOrbit\Auth\UserProvider;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Headers;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Upload\MultipartParser;
use PhpOrbit\Http\Upload\UploadedFile;
use PhpOrbit\Http\Upload\UploadError;
use PhpOrbit\Http\Uri;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Session\Session;
use PhpOrbit\Session\SessionMiddleware;
use PhpOrbit\Tests\Support\ArraySessionStore;
use PhpOrbit\Tests\Support\ArrayUserProvider;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Uploads and authentication under a long-lived process.
 *
 * Both are places where per-request state is easy to leak: an upload leaves a
 * file on disk, and an authenticated user is exactly the kind of object one
 * request must never hand to the next.
 */
final class UploadAndAuthIsolationTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/orbit-worker-uploads-' . bin2hex(random_bytes(6));

        mkdir($this->temporary, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->temporary);
    }

    /**
     * The cleanup contract: a handler that ignores an upload must not leave
     * the temporary file behind. Over a worker's lifetime that is a disk leak.
     */
    public function test_an_ignored_upload_is_deleted_when_the_request_ends(): void
    {
        $captured = [];

        $app = Application::boot(static function (Blueprint $app) use (&$captured): void {
            $app->routes->post('/ignore', static function (ServerRequest $r) use (&$captured): Response {
                $captured[] = $r->file('photo');

                return Response::text('ignored');
            }, csrfExempt: true);
        });

        for ($i = 0; $i < 3; $i++) {
            $app->handle($this->uploadRequest('/ignore', 'photo', 'p.txt', 'data ' . $i));
        }

        self::assertCount(3, $captured);

        foreach ($captured as $file) {
            self::assertInstanceOf(UploadedFile::class, $file);
            self::assertFalse($file->isValid(), 'the temporary file should be gone');
        }

        self::assertSame([], glob($this->temporary . '/orbit-upload-*') ?: []);
    }

    /**
     * Cleanup runs from a finally block, so it also covers the error path —
     * the case most likely to leave litter behind.
     */
    public function test_an_upload_is_cleaned_up_when_the_handler_throws(): void
    {
        $captured = null;

        $app = Application::boot(static function (Blueprint $app) use (&$captured): void {
            $app->routes->post('/boom', static function (ServerRequest $r) use (&$captured): Response {
                $captured = $r->file('photo');

                throw new RuntimeException('handler failed');
            }, csrfExempt: true);
        });

        $app->handle($this->uploadRequest('/boom', 'photo', 'p.txt', 'data'));

        self::assertInstanceOf(UploadedFile::class, $captured);
        self::assertFalse($captured->isValid());
    }

    /**
     * A file the handler stored belongs to the application and must survive.
     */
    public function test_a_moved_upload_is_not_deleted(): void
    {
        $destination = $this->temporary . '/stored';
        mkdir($destination, 0o750, true);

        $app = Application::boot(static function (Blueprint $app) use ($destination): void {
            $app->routes->post('/keep', static function (ServerRequest $r) use ($destination): Response {
                $file = $r->file('photo');

                assert($file instanceof UploadedFile);

                return Response::text($file->moveTo($destination, 'kept.txt'));
            }, csrfExempt: true);
        });

        $path = $app->handle($this->uploadRequest('/keep', 'photo', 'p.txt', 'keep me'))->body;

        self::assertFileExists($path);
        self::assertSame('keep me', file_get_contents($path));

        @unlink($path);
        @rmdir($destination);
    }

    /**
     * Two requests carrying different files must not see each other's.
     */
    public function test_uploads_do_not_bleed_between_requests(): void
    {
        $app = Application::boot(static function (Blueprint $app): void {
            $app->routes->post('/echo', static function (ServerRequest $r): Response {
                $file = $r->file('photo');

                return Response::text($file === null ? 'none' : $file->contents());
            }, csrfExempt: true);
        });

        self::assertSame('first', $app->handle($this->uploadRequest('/echo', 'photo', 'a.txt', 'first'))->body);
        self::assertSame('second', $app->handle($this->uploadRequest('/echo', 'photo', 'b.txt', 'second'))->body);
        self::assertSame('none', $app->handle(Requests::post('/echo'))->body);
    }

    /**
     * The authenticated user is per-request state of the most sensitive kind.
     */
    public function test_authentication_does_not_leak_between_visitors(): void
    {
        $sessions = new ArraySessionStore();
        $hasher = new PasswordHasher();
        $users = new ArrayUserProvider();
        $users->add('1', 'ada@example.test', $hasher->hash('pw-ada'));
        $users->add('2', 'grace@example.test', $hasher->hash('pw-grace'));

        $app = Application::boot(static function (Blueprint $app) use ($sessions, $users, $hasher): void {
            $app->container->singleton(UserProvider::class, static fn (): UserProvider => $users);
            $app->container->singleton(PasswordHasher::class, static fn (): PasswordHasher => $hasher);

            $app->middleware(new SessionMiddleware($sessions));

            $app->routes->post('/sign-in/{email}', static function (
                ServerRequest $r,
                RequestScope $scope,
            ): Response {
                $email = (string) $r->attribute('email') . '@example.test';
                $password = 'pw-' . $r->attribute('email');

                $scope->get(Authenticator::class)->attempt($email, $password);

                return Response::text('ok');
            }, csrfExempt: true);

            $app->routes->get('/whoami', static fn (ServerRequest $r, RequestScope $scope): Response => Response::text(
                $scope->get(Authenticator::class)->user()?->authIdentifier() ?? 'guest',
            ));
        });

        $ada = $this->sessionCookie($app->handle(Requests::form('/sign-in/ada', [])));
        $grace = $this->sessionCookie($app->handle(Requests::form('/sign-in/grace', [])));

        self::assertNotSame($ada, $grace);

        // Interleave the two visitors through the same booted application.
        self::assertSame('1', $this->whoami($app, $ada));
        self::assertSame('2', $this->whoami($app, $grace));
        self::assertSame('1', $this->whoami($app, $ada));
        self::assertSame('guest', $app->handle(Requests::get('/whoami'))->body);
    }

    /**
     * The Authenticator caches its lookup within a request; that cache must
     * not be shared, or the second visitor would see the first.
     */
    public function test_the_authenticator_is_rebuilt_for_each_request(): void
    {
        $sessions = new ArraySessionStore();
        $hasher = new PasswordHasher();
        $users = new ArrayUserProvider();
        $users->add('1', 'ada@example.test', $hasher->hash('pw'));

        $instances = [];

        $app = Application::boot(static function (Blueprint $app) use ($sessions, $users, $hasher, &$instances): void {
            $app->container->singleton(UserProvider::class, static fn (): UserProvider => $users);
            $app->container->singleton(PasswordHasher::class, static fn (): PasswordHasher => $hasher);

            $app->middleware(new SessionMiddleware($sessions));

            $app->routes->get('/probe', static function (
                ServerRequest $r,
                RequestScope $scope,
            ) use (&$instances): Response {
                $instances[] = spl_object_id($scope->get(Authenticator::class));

                return Response::text('ok');
            });
        });

        $app->handle(Requests::get('/probe'));
        $app->handle(Requests::get('/probe'));

        self::assertCount(2, $instances);
        self::assertNotSame($instances[0], $instances[1]);
    }

    /**
     * The session object itself must also be per-request.
     */
    public function test_the_session_object_is_rebuilt_for_each_request(): void
    {
        $sessions = new ArraySessionStore();
        $ids = [];

        $app = Application::boot(static function (Blueprint $app) use ($sessions, &$ids): void {
            $app->middleware(new SessionMiddleware($sessions));

            $app->routes->get('/probe', static function (
                ServerRequest $r,
                RequestScope $scope,
            ) use (&$ids): Response {
                $ids[] = spl_object_id($scope->get(Session::class));

                return Response::text('ok');
            });
        });

        $app->handle(Requests::get('/probe'));
        $app->handle(Requests::get('/probe'));

        self::assertNotSame($ids[0], $ids[1]);
    }

    // --- helpers -------------------------------------------------------------

    private function uploadRequest(string $path, string $field, string $filename, string $contents): ServerRequest
    {
        $boundary = '----orbitworker';

        $body = '--' . $boundary . "\r\n"
            . sprintf("Content-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\n", $field, $filename)
            . "Content-Type: text/plain\r\n\r\n"
            . $contents . "\r\n"
            . '--' . $boundary . "--\r\n";

        $headers = Headers::fromArray([
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ]);

        $parsed = (new MultipartParser(temporaryDirectory: $this->temporary))->parse($headers, $body);

        return new ServerRequest(
            Method::Post,
            Uri::fromRequestTarget($path, 'http', 'localhost', 8080),
            $headers,
            $body,
            form: $parsed->fields,
            files: $parsed->files,
        );
    }

    private function whoami(Application $app, string $cookie): string
    {
        return $app->handle(Requests::of(
            Method::Get,
            '/whoami',
            cookies: ['orbit_session' => $cookie],
        ))->body;
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
