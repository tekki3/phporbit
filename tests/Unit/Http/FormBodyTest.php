<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Http;

use PhpOrbit\Http\FormBody;
use PhpOrbit\Http\Headers;
use PHPUnit\Framework\TestCase;

final class FormBodyTest extends TestCase
{
    public function test_it_decodes_urlencoded_fields(): void
    {
        $fields = FormBody::parse(
            Headers::fromArray(['Content-Type' => 'application/x-www-form-urlencoded']),
            'title=Hello+World&body=a%26b',
        );

        self::assertSame(['title' => 'Hello World', 'body' => 'a&b'], $fields);
    }

    public function test_it_tolerates_content_type_parameters(): void
    {
        $fields = FormBody::parse(
            Headers::fromArray(['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8']),
            'a=1',
        );

        self::assertSame(['a' => '1'], $fields);
    }

    /**
     * A JSON body must not be reinterpreted as a form, or a request could
     * smuggle in fields the caller never sent as form data.
     */
    public function test_it_ignores_other_media_types(): void
    {
        self::assertSame([], FormBody::parse(
            Headers::fromArray(['Content-Type' => 'application/json']),
            '{"a":1}',
        ));
    }

    public function test_it_ignores_a_body_with_no_content_type(): void
    {
        self::assertSame([], FormBody::parse(Headers::empty(), 'a=1'));
    }

    /**
     * Nested inputs decode to arrays, which have no unambiguous string form.
     */
    public function test_it_drops_nested_fields(): void
    {
        $fields = FormBody::parse(
            Headers::fromArray(['Content-Type' => 'application/x-www-form-urlencoded']),
            'flat=1&nested[a]=2',
        );

        self::assertSame(['flat' => '1'], $fields);
    }
}
