<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Http;

use InvalidArgumentException;
use PhpOrbit\Http\Cookie;
use PhpOrbit\Http\SameSite;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CookieTest extends TestCase
{
    public function test_defaults_are_the_restrictive_ones(): void
    {
        $header = (new Cookie('session', 'abc'))->toHeaderValue();

        self::assertStringContainsString('session=abc', $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('Secure', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
        self::assertStringContainsString('Path=/', $header);
    }

    /**
     * A Secure cookie is never sent over plain HTTP, so building one from the
     * request keeps the dev server working without weakening production.
     */
    public function test_secure_follows_the_request_scheme(): void
    {
        $overHttp = Cookie::forRequest(Requests::get('/'), 'session', 'abc');

        self::assertFalse($overHttp->secure);
        self::assertStringNotContainsString('Secure', $overHttp->toHeaderValue());
    }

    public function test_expired_cookies_carry_a_past_expiry(): void
    {
        $header = Cookie::expired('session', secure: false)->toHeaderValue();

        self::assertStringContainsString('session=;', $header . ';');
        self::assertStringContainsString('Max-Age=0', $header);
    }

    #[DataProvider('injectionValues')]
    public function test_it_rejects_values_that_could_forge_attributes(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cookie('session', $value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function injectionValues(): iterable
    {
        yield 'semicolon starts a new attribute' => ['abc; Domain=evil.test'];
        yield 'comma splits cookies' => ['abc,def'];
        yield 'newline forges a header' => ["abc\r\nSet-Cookie: x=y"];
        yield 'space' => ['a b'];
        yield 'quote' => ['a"b'];
    }

    public function test_it_rejects_an_invalid_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cookie('bad name', 'value');
    }

    /**
     * Browsers silently drop SameSite=None without Secure, which would look
     * like the cookie was never set at all.
     */
    public function test_samesite_none_requires_secure(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cookie('session', 'abc', secure: false, sameSite: SameSite::None);
    }
}
