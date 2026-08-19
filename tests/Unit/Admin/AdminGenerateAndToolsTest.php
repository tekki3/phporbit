<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Admin;

use FilesystemIterator;
use PhpOrbit\Admin\AdminApplication;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Status;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Tests\Support\Requests;
use PhpOrbit\Tests\Unit\Routing\GreetingRoute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every `orbit make:*` and `orbit key:generate` / `orbit mail:test` operation
 * is also reachable as a web form here — the point of this file is to prove
 * each one actually writes what the CLI equivalent writes, through the same
 * CSRF-protected request cycle a browser would use.
 */
final class AdminGenerateAndToolsTest extends TestCase
{
    private string $workspace;

    private Application $admin;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/orbit-admin-gen-' . bin2hex(random_bytes(6));

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

        $this->admin = AdminApplication::boot($this->workspace);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    // --- class ------------------------------------------------------------

    public function test_generating_a_class_writes_the_file_and_shows_the_result(): void
    {
        [$token, $cookies] = $this->csrfTokenAndCookies('/generate/class');

        $response = $this->admin->handle(Requests::form('/generate/class', [
            '_token' => $token,
            'name' => 'Notes/NoteRepository',
            'lifetime' => 'singleton',
        ], $cookies));

        self::assertSame(Status::Ok, $response->status);
        self::assertStringContainsString('app/src/Notes/NoteRepository.php', $response->body);
        self::assertFileExists($this->workspace . '/app/src/Notes/NoteRepository.php');

        $source = (string) file_get_contents($this->workspace . '/app/src/Notes/NoteRepository.php');
        self::assertStringContainsString('namespace App\Notes;', $source);
        self::assertStringContainsString('final class NoteRepository', $source);
        // A singleton, as requested — not the default autowired lifetime.
        self::assertStringContainsString('must be stateless', $source);
    }

    /**
     * A reserved word is refused by ClassMaker; the page must show why and
     * keep the field so it is not retyped from scratch.
     */
    public function test_a_bad_class_name_redisplays_the_form_with_the_error(): void
    {
        [$token, $cookies] = $this->csrfTokenAndCookies('/generate/class');

        $response = $this->admin->handle(Requests::form('/generate/class', [
            '_token' => $token,
            'name' => 'List',
            'lifetime' => 'autowired',
        ], $cookies));

        self::assertSame(Status::UnprocessableEntity, $response->status);
        self::assertStringContainsString('reserved word', $response->body);
        self::assertStringContainsString('value="List"', $response->body);
    }

    // --- controller ---------------------------------------------------------

    public function test_generating_a_controller_with_a_view_writes_both_files(): void
    {
        [$token, $cookies] = $this->csrfTokenAndCookies('/generate/controller');

        $response = $this->admin->handle(Requests::form('/generate/controller', [
            '_token' => $token,
            'name' => 'Reports',
            'withView' => '1',
        ], $cookies));

        self::assertSame(Status::Ok, $response->status);
        self::assertFileExists($this->workspace . '/app/src/Controllers/ReportsController.php');
        self::assertFileExists($this->workspace . '/app/templates/reports.orbit.php');
        self::assertStringContainsString('routes-&gt;get', $response->body);
    }

    // --- middleware -----------------------------------------------------------

    public function test_generating_middleware_writes_a_pass_through_class(): void
    {
        [$token, $cookies] = $this->csrfTokenAndCookies('/generate/middleware');

        $response = $this->admin->handle(Requests::form('/generate/middleware', [
            '_token' => $token,
            'name' => 'RequestId',
        ], $cookies));

        self::assertSame(Status::Ok, $response->status);
        self::assertFileExists($this->workspace . '/app/src/Middleware/RequestIdMiddleware.php');
        self::assertStringContainsString(
            'implements Middleware',
            (string) file_get_contents($this->workspace . '/app/src/Middleware/RequestIdMiddleware.php'),
        );
    }

    // --- migration --------------------------------------------------------

    public function test_generating_a_migration_infers_a_create_table_shape(): void
    {
        [$token, $cookies] = $this->csrfTokenAndCookies('/generate/migration');

        $response = $this->admin->handle(Requests::form('/generate/migration', [
            '_token' => $token,
            'name' => 'create_articles_table',
            'sequential' => '1',
        ], $cookies));

        self::assertSame(Status::Ok, $response->status);

        $written = glob($this->workspace . '/database/migrations/*create_articles_table.php') ?: [];
        self::assertCount(1, $written);
        self::assertStringContainsString('CREATE TABLE articles', (string) file_get_contents($written[0]));
    }

    // --- form ---------------------------------------------------------------

    public function test_generating_a_form_with_controllers_writes_the_route_snippet(): void
    {
        [$token, $cookies] = $this->csrfTokenAndCookies('/generate/form');

        $response = $this->admin->handle(Requests::form('/generate/form', [
            '_token' => $token,
            'name' => 'Signup',
            'fields' => 'email:email',
            'honeypot' => '1',
            'controllers' => '1',
        ], $cookies));

        self::assertSame(Status::Ok, $response->status);
        self::assertFileExists($this->workspace . '/app/src/Forms/SignupForm.php');
        self::assertFileExists($this->workspace . '/app/src/Controllers/SignupController.php');
        self::assertFileExists($this->workspace . '/app/src/Controllers/SubmitSignupController.php');

        // Escaped, so the assertion matches what actually reaches the page.
        self::assertStringContainsString('SignupController;', $response->body);
        self::assertStringContainsString("routes-&gt;post(&apos;/signup&apos;", $response->body);
    }

    /**
     * The field the honeypot itself emits: declaring it too must be refused,
     * the same as `orbit make:form` refuses it on the command line.
     */
    public function test_a_field_clashing_with_the_honeypot_is_refused(): void
    {
        [$token, $cookies] = $this->csrfTokenAndCookies('/generate/form');

        $response = $this->admin->handle(Requests::form('/generate/form', [
            '_token' => $token,
            'name' => 'Contact',
            'fields' => 'website:text',
            'honeypot' => '1',
        ], $cookies));

        self::assertSame(Status::UnprocessableEntity, $response->status);
        self::assertStringContainsString('honeypot', $response->body);
        self::assertFileDoesNotExist($this->workspace . '/app/src/Forms/ContactForm.php');
    }

    // --- tools ----------------------------------------------------------------

    public function test_generating_a_key_shows_it_without_writing_anything(): void
    {
        [$token, $cookies] = $this->csrfTokenAndCookies('/tools');

        $response = $this->admin->handle(Requests::form('/tools/key', ['_token' => $token], $cookies));

        self::assertSame(Status::Ok, $response->status);
        self::assertMatchesRegularExpression('/APP_KEY=base64:[A-Za-z0-9+\/]+=*/', $response->body);
        self::assertFileDoesNotExist($this->workspace . '/.env.generated');
    }

    public function test_sending_a_test_message_reports_the_array_driver_did_not_deliver_it(): void
    {
        [$token, $cookies] = $this->csrfTokenAndCookies('/tools');

        $response = $this->admin->handle(Requests::form('/tools/mail-test', [
            '_token' => $token,
            'to' => 'someone@example.test',
            'from' => 'me@example.test',
        ], $cookies));

        self::assertSame(Status::Ok, $response->status);
        self::assertStringContainsString('Accepted by the', $response->body);
        self::assertStringContainsString('nothing left this machine', $response->body);
    }

    public function test_sending_a_test_message_without_a_sender_is_refused(): void
    {
        [$token, $cookies] = $this->csrfTokenAndCookies('/tools');

        $response = $this->admin->handle(Requests::form('/tools/mail-test', [
            '_token' => $token,
            'to' => 'someone@example.test',
        ], $cookies));

        self::assertSame(Status::UnprocessableEntity, $response->status);
        self::assertStringContainsString('No sender configured', $response->body);
    }

    /**
     * A generated test send is still a real send, so it still goes through
     * PersistingMailer and lands in mail_log like any other.
     */
    public function test_a_test_message_is_logged_like_any_other_send(): void
    {
        // database/migrations already exists (setUp creates it); this is the
        // one test in the file that actually needs a migration to run.
        copy(
            dirname(__DIR__, 3) . '/stubs/blank/database/migrations/0001_create_mail_log_table.php',
            $this->workspace . '/database/migrations/0001_create_mail_log_table.php',
        );

        [$migrateToken, $migrateCookies] = $this->csrfTokenAndCookies('/migrations');
        $this->admin->handle(Requests::form('/migrations/run', ['_token' => $migrateToken], $migrateCookies));

        [$token, $cookies] = $this->csrfTokenAndCookies('/tools', $migrateCookies);
        $this->admin->handle(Requests::form('/tools/mail-test', [
            '_token' => $token,
            'to' => 'someone@example.test',
            'from' => 'me@example.test',
        ], $cookies));

        $mailPage = $this->admin->handle(Requests::of(Method::Get, '/mail', cookies: $cookies));
        self::assertStringContainsString('phporbit test message', $mailPage->body);
    }

    // --- csrf, spot-checked across the new routes --------------------------

    #[DataProvider('newPostRoutes')]
    public function test_new_post_routes_all_require_a_csrf_token(string $path): void
    {
        $response = $this->admin->handle(Requests::post($path));

        self::assertSame(Status::Forbidden, $response->status);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function newPostRoutes(): iterable
    {
        yield 'class' => ['/generate/class'];
        yield 'controller' => ['/generate/controller'];
        yield 'form' => ['/generate/form'];
        yield 'middleware' => ['/generate/middleware'];
        yield 'migration' => ['/generate/migration'];
        yield 'key' => ['/tools/key'];
        yield 'mail-test' => ['/tools/mail-test'];
    }

    // --- helpers --------------------------------------------------------------

    /**
     * @param array<string, string> $existingCookies
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
