<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\App;

use App\Middleware\HtmlErrorPages;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\Status;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Tests\Support\Requests;
use PhpOrbit\View\TemplateEngine;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exercises {@see HtmlErrorPages} against the demo's real templates.
 *
 * Points a real {@see \PhpOrbit\View\TemplateEngine} at `app/templates`
 * rather than a fixture directory — the same reasoning as
 * {@see RoutesFileTest} loading the real `app/routes.php` — so a change that
 * breaks `errors/error.orbit.php` or the layout it extends fails here rather
 * than only being caught by clicking around a running server.
 */
final class HtmlErrorPagesTest extends TestCase
{
    private string $cache;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/orbit-error-pages-' . bin2hex(random_bytes(6));

        mkdir($this->cache, 0o750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cache . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->cache);
    }

    public function test_an_unmatched_route_gets_the_html_404_page(): void
    {
        $response = $this->app()->handle(Requests::get('/missing'));

        self::assertSame(Status::NotFound, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->headers->first('Content-Type'));
        self::assertStringContainsString('Page not found', $response->body);
    }

    public function test_a_wrong_method_gets_the_html_405_page_with_the_allow_header(): void
    {
        $response = $this->app()->handle(Requests::post('/'));

        self::assertSame(Status::MethodNotAllowed, $response->status);
        self::assertSame('GET', $response->headers->first('Allow'));
        self::assertStringContainsString('Method not allowed', $response->body);
    }

    public function test_a_thrown_exception_is_opaque_outside_debug_mode(): void
    {
        $response = $this->app(debug: false)->handle(Requests::get('/boom'));

        self::assertSame(Status::InternalServerError, $response->status);
        self::assertStringContainsString('Something went wrong', $response->body);
        self::assertStringNotContainsString('hunter2', $response->body);
    }

    public function test_debug_mode_shows_the_failure_on_the_page(): void
    {
        $response = $this->app(debug: true)->handle(Requests::get('/boom'));

        self::assertSame(Status::InternalServerError, $response->status);
        self::assertStringContainsString('hunter2', $response->body);
    }

    /**
     * The JSON 401 `RequireAuthentication` sends to a non-browser client is
     * deliberately outside this page's list — swapping it for HTML would
     * break every caller that expects a machine-readable body.
     */
    public function test_a_status_it_does_not_recognise_passes_through_untouched(): void
    {
        $response = $this->app()->handle(Requests::get('/api-error'));

        self::assertSame(Status::Unauthorized, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers->first('Content-Type'));
        self::assertStringContainsString('"error"', $response->body);
    }

    public function test_an_ordinary_response_passes_through_untouched(): void
    {
        $response = $this->app()->handle(Requests::get('/'));

        self::assertSame(Status::Ok, $response->status);
        self::assertSame('root', $response->body);
    }

    private function app(bool $debug = false): Application
    {
        $view = new TemplateEngine(
            dirname(__DIR__, 3) . '/app/templates',
            $this->cache,
            alwaysRecompile: true,
            shared: [
                'appUrl' => 'https://example.test',
                'hasDocs' => false,
                'sapi' => 'cli',
                'phpVersion' => PHP_VERSION,
            ],
        );

        return Application::boot(static function (Blueprint $app) use ($view, $debug): void {
            $app->middleware(new HtmlErrorPages($view, $debug));

            $app->routes->get('/', static fn (): Response => Response::text('root'));

            $app->routes->get('/boom', static function (): Response {
                throw new RuntimeException('connection string: user:hunter2@db');
            });

            $app->routes->get(
                '/api-error',
                static fn (): Response => Response::json(['error' => 'authentication required'], Status::Unauthorized),
            );
        }, debug: $debug);
    }
}
