<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Security;

use PhpOrbit\Security\Escaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EscaperTest extends TestCase
{
    public function test_html_escapes_tags_and_quotes(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;',
            Escaper::html('<script>alert("x")</script>'),
        );
    }

    /**
     * The attribute escaper must hold even when the template author omitted
     * the quotes around the attribute value.
     */
    public function test_attribute_escaping_survives_an_unquoted_attribute(): void
    {
        $escaped = Escaper::attribute('x onmouseover=alert(1)');

        self::assertStringNotContainsString(' ', $escaped);
        self::assertStringNotContainsString('=', $escaped);
    }

    public function test_js_produces_a_quoted_literal(): void
    {
        $escaped = Escaper::js('</script><script>alert(1)</script>');

        self::assertStringStartsWith('"', $escaped);
        self::assertStringEndsWith('"', $escaped);
        self::assertStringNotContainsString('</script>', $escaped);
    }

    #[DataProvider('dangerousUrls')]
    public function test_it_neutralises_dangerous_url_schemes(string $url): void
    {
        self::assertSame('#', Escaper::urlAttribute($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dangerousUrls(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
        yield 'leading whitespace' => ['  javascript:alert(1)'];
    }

    #[DataProvider('safeUrls')]
    public function test_it_allows_ordinary_urls(string $url): void
    {
        self::assertNotSame('#', Escaper::urlAttribute($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeUrls(): iterable
    {
        yield 'https' => ['https://example.test/page'];
        yield 'http' => ['http://example.test/page'];
        yield 'mailto' => ['mailto:someone@example.test'];
        yield 'relative' => ['/local/path'];
    }
}
