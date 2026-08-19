<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Sapi;

use PhpOrbit\Http\Exception\MalformedRequest;
use PhpOrbit\Http\Method;
use PhpOrbit\Sapi\RequestParser;
use PHPUnit\Framework\TestCase;

final class RequestParserTest extends TestCase
{
    public function test_it_parses_a_complete_request(): void
    {
        $request = $this->parse(
            "POST /submit?x=1 HTTP/1.1\r\n"
            . "Host: example.test:8080\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: 13\r\n"
            . "Cookie: session=abc; theme=dark\r\n"
            . "\r\n"
            . '{"key":"v"}  ',
        );

        self::assertNotNull($request);
        self::assertSame(Method::Post, $request->method);
        self::assertSame('/submit', $request->uri->path);
        self::assertSame('1', $request->uri->queryParam('x'));
        self::assertSame('example.test', $request->uri->host);
        self::assertSame(8080, $request->uri->port);
        self::assertSame('application/json', $request->headers->first('Content-Type'));
        self::assertSame('abc', $request->cookie('session'));
        self::assertSame('dark', $request->cookie('theme'));
        self::assertSame('{"key":"v"}  ', $request->body);
    }

    public function test_it_returns_null_when_the_peer_closes(): void
    {
        self::assertNull($this->parse(''));
    }

    public function test_it_rejects_a_malformed_request_line(): void
    {
        $this->expectException(MalformedRequest::class);

        $this->parse("GARBAGE\r\n\r\n");
    }

    public function test_it_rejects_an_unsupported_http_version(): void
    {
        $this->expectException(MalformedRequest::class);

        $this->parse("GET / HTTP/2.0\r\nHost: x\r\n\r\n");
    }

    public function test_it_rejects_a_non_numeric_content_length(): void
    {
        $this->expectException(MalformedRequest::class);

        $this->parse("POST / HTTP/1.1\r\nHost: x\r\nContent-Length: abc\r\n\r\n");
    }

    /**
     * An oversized body must be refused before it is read into memory.
     */
    public function test_it_rejects_a_body_over_the_limit(): void
    {
        $this->expectException(MalformedRequest::class);
        $this->expectExceptionMessageMatches('/exceeds/');

        $this->parse("POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 999999\r\n\r\n", new RequestParser(maxBodyBytes: 16));
    }

    public function test_it_rejects_too_many_headers(): void
    {
        $headers = str_repeat("X-Pad: value\r\n", 200);

        $this->expectException(MalformedRequest::class);
        $this->expectExceptionMessageMatches('/too large/');

        $this->parse("GET / HTTP/1.1\r\nHost: x\r\n" . $headers . "\r\n");
    }

    public function test_it_rejects_chunked_encoding_explicitly(): void
    {
        $this->expectException(MalformedRequest::class);
        $this->expectExceptionMessageMatches('/[Cc]hunked/');

        $this->parse("POST / HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\n");
    }

    public function test_it_rejects_a_traversal_path(): void
    {
        $this->expectException(MalformedRequest::class);

        $this->parse("GET /../../etc/passwd HTTP/1.1\r\nHost: x\r\n\r\n");
    }

    private function parse(string $raw, ?RequestParser $parser = null): ?\PhpOrbit\Http\ServerRequest
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        fwrite($stream, $raw);
        rewind($stream);

        try {
            return ($parser ?? new RequestParser())->parse($stream, 'http', 'localhost', 8080);
        } finally {
            fclose($stream);
        }
    }
}
