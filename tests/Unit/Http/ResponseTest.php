<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Http;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\Status;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function test_it_is_immutable(): void
    {
        $original = Response::text('body');
        $modified = $original->withStatus(Status::Created)->withBody('other');

        self::assertSame(Status::Ok, $original->status);
        self::assertSame('body', $original->body);
        self::assertSame(Status::Created, $modified->status);
    }

    public function test_content_types_always_state_a_charset(): void
    {
        self::assertSame('text/plain; charset=utf-8', Response::text('x')->headers->first('Content-Type'));
        self::assertSame('text/html; charset=utf-8', Response::html('x')->headers->first('Content-Type'));
        self::assertSame('application/json; charset=utf-8', Response::json([])->headers->first('Content-Type'));
    }

    /**
     * A JSON payload inlined into a page must not be able to close the script
     * element it sits in.
     */
    public function test_json_escapes_html_significant_characters(): void
    {
        $response = Response::json(['x' => '</script><script>alert(1)</script>']);

        self::assertStringNotContainsString('<', $response->body);
        self::assertStringNotContainsString('>', $response->body);
    }

    public function test_a_204_carries_no_body_on_the_wire(): void
    {
        $response = Response::noContent()->withBody('should not be sent');

        self::assertSame('should not be sent', $response->body);
        self::assertSame('', $response->wireBody());
    }

    public function test_handlers_can_override_a_default_security_header(): void
    {
        $response = Response::html('<p>x</p>')->withHeader('X-Frame-Options', 'SAMEORIGIN');

        self::assertSame('SAMEORIGIN', $response->headers->first('X-Frame-Options'));
    }

    public function test_redirect_sets_the_location(): void
    {
        $response = Response::redirect('/elsewhere');

        self::assertSame(Status::Found, $response->status);
        self::assertSame('/elsewhere', $response->headers->first('Location'));
    }
}
