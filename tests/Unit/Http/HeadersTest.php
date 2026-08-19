<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Http;

use PhpOrbit\Http\Exception\MalformedRequest;
use PhpOrbit\Http\Headers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeadersTest extends TestCase
{
    public function test_lookup_is_case_insensitive_but_casing_is_preserved(): void
    {
        $headers = Headers::empty()->with('Content-Type', 'text/plain');

        self::assertSame('text/plain', $headers->first('content-type'));
        self::assertSame('text/plain', $headers->first('CONTENT-TYPE'));
        self::assertSame([['Content-Type', 'text/plain']], $headers->toWire());
    }

    public function test_with_replaces_and_add_appends(): void
    {
        $headers = Headers::empty()
            ->add('Set-Cookie', 'a=1')
            ->add('Set-Cookie', 'b=2');

        self::assertSame(['a=1', 'b=2'], $headers->all('Set-Cookie'));

        self::assertSame(['c=3'], $headers->with('Set-Cookie', 'c=3')->all('Set-Cookie'));
    }

    public function test_it_is_immutable(): void
    {
        $original = Headers::empty()->with('X-One', '1');
        $modified = $original->with('X-Two', '2');

        self::assertFalse($original->has('X-Two'));
        self::assertTrue($modified->has('X-Two'));
    }

    /**
     * A CR or LF reaching the wire lets an attacker append headers of their
     * own or split the response entirely.
     */
    #[DataProvider('injectionPayloads')]
    public function test_it_rejects_header_injection(string $value): void
    {
        $this->expectException(MalformedRequest::class);

        Headers::empty()->with('X-Test', $value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function injectionPayloads(): iterable
    {
        yield 'crlf' => ["one\r\nX-Injected: yes"];
        yield 'lf' => ["one\nX-Injected: yes"];
        yield 'cr' => ["one\rX-Injected: yes"];
        yield 'nul' => ["one\0two"];
    }

    public function test_it_rejects_an_invalid_field_name(): void
    {
        $this->expectException(MalformedRequest::class);

        Headers::empty()->with('X Test', 'value');
    }
}
