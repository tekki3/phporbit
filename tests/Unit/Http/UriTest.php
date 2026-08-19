<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Http;

use PhpOrbit\Http\Exception\MalformedRequest;
use PhpOrbit\Http\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UriTest extends TestCase
{
    public function test_it_parses_path_and_query(): void
    {
        $uri = Uri::fromRequestTarget('/search?q=cats&page=2', 'https', 'example.test', 443);

        self::assertSame('/search', $uri->path);
        self::assertSame('cats', $uri->queryParam('q'));
        self::assertSame('2', $uri->queryParam('page'));
        self::assertNull($uri->queryParam('missing'));
        self::assertTrue($uri->isSecure());
    }

    public function test_it_omits_the_default_port_from_the_authority(): void
    {
        self::assertSame(
            'example.test',
            Uri::fromRequestTarget('/', 'https', 'example.test', 443)->authority(),
        );

        self::assertSame(
            'example.test:8443',
            Uri::fromRequestTarget('/', 'https', 'example.test', 8443)->authority(),
        );
    }

    #[DataProvider('traversalPaths')]
    public function test_it_resolves_dot_segments(string $target, string $expected): void
    {
        self::assertSame($expected, Uri::fromRequestTarget($target, 'http', 'localhost', 80)->path);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function traversalPaths(): iterable
    {
        yield 'current dir' => ['/a/./b', '/a/b'];
        yield 'parent dir' => ['/a/b/../c', '/a/c'];
        yield 'empty segments' => ['/a//b', '/a/b'];
        yield 'trailing slash kept' => ['/a/b/', '/a/b/'];
        yield 'root' => ['/', '/'];
        yield 'back to root' => ['/a/..', '/'];
    }

    /**
     * A path that climbs above the root can only be an attack, so it is
     * rejected rather than clamped to '/'.
     */
    public function test_it_rejects_a_path_escaping_the_root(): void
    {
        $this->expectException(MalformedRequest::class);

        Uri::fromRequestTarget('/../../etc/passwd', 'http', 'localhost', 80);
    }

    public function test_it_rejects_a_nul_byte_in_the_path(): void
    {
        $this->expectException(MalformedRequest::class);

        Uri::fromRequestTarget("/a\0b", 'http', 'localhost', 80);
    }

    public function test_it_rejects_an_encoded_nul_byte(): void
    {
        $this->expectException(MalformedRequest::class);

        Uri::fromRequestTarget('/a%00b', 'http', 'localhost', 80);
    }

    public function test_it_percent_decodes_path_segments(): void
    {
        self::assertSame(
            '/hello/<script>',
            Uri::fromRequestTarget('/hello/%3Cscript%3E', 'http', 'localhost', 80)->path,
        );

        self::assertSame(
            '/files/my file.txt',
            Uri::fromRequestTarget('/files/my%20file.txt', 'http', 'localhost', 80)->path,
        );
    }

    /**
     * Traversal has to be caught after decoding, or `%2E%2E` walks straight
     * past a check that only looks for a literal `..`.
     */
    public function test_it_resolves_encoded_dot_segments(): void
    {
        self::assertSame(
            '/a/c',
            Uri::fromRequestTarget('/a/b/%2E%2E/c', 'http', 'localhost', 80)->path,
        );

        $this->expectException(MalformedRequest::class);

        Uri::fromRequestTarget('/%2E%2E/%2E%2E/etc/passwd', 'http', 'localhost', 80);
    }

    /**
     * Decoding happens after splitting, so an encoded slash can never create
     * a new path segment.
     */
    public function test_it_rejects_an_encoded_path_separator(): void
    {
        $this->expectException(MalformedRequest::class);

        Uri::fromRequestTarget('/files/a%2F..%2F..%2Fetc', 'http', 'localhost', 80);
    }
}
