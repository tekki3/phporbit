<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Admin;

use FilesystemIterator;
use PhpOrbit\Admin\AdminApplication;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\DatabaseSettings;
use PhpOrbit\Config\Environment;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Status;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Mail\MailLogRepository;
use PhpOrbit\Mail\MailStatus;
use PhpOrbit\Mail\Message;
use PhpOrbit\Tests\Support\Requests;
use PhpOrbit\Tests\Unit\Routing\GreetingRoute;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The admin dashboard is a second, self-contained application — never wired
 * into app/routes.php — so it is booted and driven here the same way
 * ScaffoldTest drives a scaffolded project: real requests through
 * Application::handle(), not inspection of what was written to disk.
 */
final class AdminApplicationTest extends TestCase
{
    private string $workspace;

    private Application $admin;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/orbit-admin-' . bin2hex(random_bytes(6));

        mkdir($this->workspace . '/app', 0o755, true);
        mkdir($this->workspace . '/database/migrations', 0o755, true);

        file_put_contents($this->workspace . '/.env', implode("\n", [
            'APP_DEBUG=false',
            'LOG_LEVEL=error',
            'DB_DRIVER=sqlite',
            'DB_DATABASE=storage/app.sqlite',
            'MAIL_DRIVER=array',
            'SESSION_LIFETIME=7200',
        ]));

        // A minimal, real routes file — reusing an existing zero-dependency
        // test handler rather than inventing a new one, so /routes has
        // something genuine to read back.
        file_put_contents($this->workspace . '/app/routes.php', sprintf(
            <<<'PHP'
                <?php
                declare(strict_types=1);
                use PhpOrbit\Routing\RouteCollection;
                return static function (RouteCollection $routes, bool $debug): void {
                    $routes->get('/greet', %s::class, 'greet');
                };
                PHP,
            GreetingRoute::class,
        ));

        copy(
            dirname(__DIR__, 3) . '/stubs/blank/database/migrations/0001_create_mail_log_table.php',
            $this->workspace . '/database/migrations/0001_create_mail_log_table.php',
        );

        $this->admin = AdminApplication::boot($this->workspace);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function test_the_overview_page_renders(): void
    {
        $response = $this->admin->handle(Requests::get('/'));

        self::assertSame(Status::Ok, $response->status);
        self::assertStringContainsString('Overview', $response->body);
    }

    public function test_the_mail_page_says_so_before_migrations_have_run(): void
    {
        $response = $this->admin->handle(Requests::get('/mail'));

        self::assertSame(Status::Ok, $response->status);
        self::assertStringContainsString('does not exist yet', $response->body);
        self::assertStringContainsString('Run pending migrations', $response->body);
    }

    public function test_the_routes_page_reads_the_projects_real_routes_file(): void
    {
        $response = $this->admin->handle(Requests::get('/routes'));

        self::assertStringContainsString('/greet', $response->body);
        self::assertStringContainsString('greet', $response->body);
        self::assertStringContainsString('GET', $response->body);
    }

    public function test_a_post_without_a_csrf_token_is_rejected(): void
    {
        $response = $this->admin->handle(Requests::post('/migrations/run'));

        self::assertSame(Status::Forbidden, $response->status);
    }

    public function test_the_session_cookie_is_named_distinctly_from_the_projects_own(): void
    {
        // Any page carrying a CSRF token: minting one on first use is what
        // dirties the session and actually triggers a Set-Cookie header.
        $response = $this->admin->handle(Requests::get('/migrations'));

        $setCookie = (string) $response->headers->first('Set-Cookie');

        // Cookies are scoped by host, not port: orbit serve and orbit ui both
        // default to 127.0.0.1, so sharing SESSION_COOKIE's name would let
        // one silently overwrite the other's session.
        self::assertStringStartsWith('orbit_admin_session=', $setCookie);
    }

    /**
     * The full loop: run the pending migration through the button, confirm
     * the mail page switches from unavailable to available, seed a failed
     * send, resend it through the button, and roll the migration back again
     * — proving both migration actions and both mail actions work, not just
     * that their routes exist.
     */
    public function test_running_migrations_and_resending_mail_end_to_end(): void
    {
        [$token, $cookies] = $this->csrfTokenAndCookies('/migrations');

        $ran = $this->admin->handle(Requests::form('/migrations/run', ['_token' => $token], $cookies));
        self::assertSame(Status::Found, $ran->status);
        self::assertSame('/migrations', $ran->headers->first('Location'));

        $afterRun = $this->admin->handle(Requests::of(Method::Get, '/migrations', cookies: $cookies));
        self::assertStringContainsString('Applied 1 migration', $afterRun->body);

        $mailPage = $this->admin->handle(Requests::of(Method::Get, '/mail', cookies: $cookies));
        self::assertStringNotContainsString('does not exist yet', $mailPage->body);
        self::assertStringContainsString('No mail logged yet', $mailPage->body);

        // Seed a failed send directly, the way a real delivery failure would
        // have recorded one.
        $id = $this->mailLogRepository()->record(
            Message::to('ada@example.test')->from('a@b.test')->subject('Hi')->text('Hi'),
            MailStatus::Failed,
            'SMTP DATA failed: 550 refused',
        );

        [$mailToken, $mailCookies] = $this->csrfTokenAndCookies('/mail', $cookies);

        $resent = $this->admin->handle(Requests::form(
            "/mail/{$id}/resend",
            ['_token' => $mailToken],
            $mailCookies,
        ));
        self::assertSame(Status::Found, $resent->status);
        self::assertSame('/mail', $resent->headers->first('Location'));

        $entry = $this->mailLogRepository()->find($id);
        self::assertNotNull($entry);
        self::assertSame(MailStatus::Sent, $entry->status);
        self::assertSame(2, $entry->attempts);

        $afterResend = $this->admin->handle(Requests::of(Method::Get, '/mail', cookies: $mailCookies));
        self::assertStringContainsString("Resent #{$id}", $afterResend->body);

        // Roll back, and the mail page should say it is unavailable again —
        // proving the round trip, not just one direction of it.
        [$rollbackToken, $rollbackCookies] = $this->csrfTokenAndCookies('/migrations', $mailCookies);

        $rolledBack = $this->admin->handle(Requests::form(
            '/migrations/rollback',
            ['_token' => $rollbackToken],
            $rollbackCookies,
        ));
        self::assertSame(Status::Found, $rolledBack->status);

        $mailAfterRollback = $this->admin->handle(Requests::of(Method::Get, '/mail', cookies: $rollbackCookies));
        self::assertStringContainsString('does not exist yet', $mailAfterRollback->body);
    }

    public function test_the_storage_page_clears_the_compiled_template_cache(): void
    {
        $cacheDirectory = $this->workspace . '/storage/cache/views';
        mkdir($cacheDirectory, 0o755, true);
        file_put_contents($cacheDirectory . '/deadbeef.php', '<?php // compiled');

        [$token, $cookies] = $this->csrfTokenAndCookies('/storage');

        $before = $this->admin->handle(Requests::of(Method::Get, '/storage', cookies: $cookies));
        self::assertStringContainsString('1', $before->body);

        $cleared = $this->admin->handle(Requests::form('/storage/clear', ['_token' => $token], $cookies));
        self::assertSame(Status::Found, $cleared->status);

        self::assertFileDoesNotExist($cacheDirectory . '/deadbeef.php');

        $after = $this->admin->handle(Requests::of(Method::Get, '/storage', cookies: $cookies));
        self::assertStringContainsString('Removed 1 compiled template', $after->body);
    }

    public function test_the_sessions_page_removes_expired_sessions(): void
    {
        // Already created by AdminApplication::boot() in setUp().
        $sessionsDirectory = $this->workspace . '/storage/sessions';
        file_put_contents(
            $sessionsDirectory . '/sess_' . str_repeat('a', 64),
            json_encode(['expires' => time() - 3600, 'data' => []], JSON_THROW_ON_ERROR),
        );

        [$token, $cookies] = $this->csrfTokenAndCookies('/sessions');

        $gc = $this->admin->handle(Requests::form('/sessions/gc', ['_token' => $token], $cookies));
        self::assertSame(Status::Found, $gc->status);

        self::assertFileDoesNotExist($sessionsDirectory . '/sess_' . str_repeat('a', 64));

        $after = $this->admin->handle(Requests::of(Method::Get, '/sessions', cookies: $cookies));
        self::assertStringContainsString('Removed 1 expired session', $after->body);
    }

    // --- helpers --------------------------------------------------------------

    /**
     * GETs a page, harvests its CSRF token, and returns the session cookies
     * to reuse on the follow-up POST — the same round trip a browser makes.
     *
     * @param array<string, string> $existingCookies reuse an established session instead of starting a new one
     * @return array{0: string, 1: array<string, string>}
     */
    private function csrfTokenAndCookies(string $path, array $existingCookies = []): array
    {
        $response = $this->admin->handle(Requests::of(Method::Get, $path, cookies: $existingCookies));

        if (preg_match('/name="_token" value="([a-f0-9]+)"/', $response->body, $match) !== 1) {
            self::fail('the page carried no CSRF token');
        }

        $cookies = $existingCookies;

        foreach ($response->headers->all('Set-Cookie') as $header) {
            $pair = explode('=', explode(';', $header)[0], 2);

            if (count($pair) === 2) {
                $cookies[trim($pair[0])] = urldecode($pair[1]);
            }
        }

        return [$match[1], $cookies];
    }

    private function mailLogRepository(): MailLogRepository
    {
        $env = Environment::load($this->workspace . '/.env');

        return new MailLogRepository(Connection::connect(DatabaseSettings::fromEnvironment($env, $this->workspace)));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
