<?php

declare(strict_types=1);

namespace App\Tests;

use PhpOrbit\Http\Headers;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Http\Uri;
use PhpOrbit\Kernel\Application;
use PHPUnit\Framework\TestCase;

/**
 * A starting point.
 *
 * The application is booted once and handed a request directly — no HTTP server
 * involved, and fast enough to do in every test.
 *
 * Note that it is booted *once* and used twice. That is the shape worth
 * copying: under a worker the same application object serves thousands of
 * requests, so a test that handles only one request cannot see state leaking
 * between them.
 */
final class WelcomeTest extends TestCase
{
    public function test_the_home_page_renders(): void
    {
        $response = $this->application()->handle($this->get('/'));

        self::assertSame(Status::Ok, $response->status);
        self::assertStringContainsString('It works', $response->body);
    }

    public function test_an_unknown_path_is_a_404(): void
    {
        self::assertSame(
            Status::NotFound,
            $this->application()->handle($this->get('/nothing-here'))->status,
        );
    }

    public function test_every_response_carries_the_security_headers(): void
    {
        $headers = $this->application()->handle($this->get('/'))->headers;

        self::assertSame('nosniff', $headers->first('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->first('X-Frame-Options'));
    }

    /**
     * Two requests, one process — the shape that catches a state leak.
     */
    public function test_requests_do_not_affect_one_another(): void
    {
        $application = $this->application();

        $first = $application->handle($this->get('/'));
        $second = $application->handle($this->get('/'));

        self::assertSame($first->body, $second->body);
    }

    private function application(): Application
    {
        /** @var Application $application */
        $application = require dirname(__DIR__) . '/app/bootstrap.php';

        return $application;
    }

    private function get(string $path): ServerRequest
    {
        return new ServerRequest(
            Method::Get,
            Uri::fromRequestTarget($path, 'http', 'localhost', 8080),
            Headers::empty(),
        );
    }
}
