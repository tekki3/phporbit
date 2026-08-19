<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Support;

use PhpOrbit\Http\FormBody;
use PhpOrbit\Http\Headers;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Uri;

/**
 * Builds requests without going through a SAPI.
 *
 * Tests construct requests directly so they exercise the pipeline rather than
 * the environment; the adapters are tested separately.
 */
final class Requests
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    public static function of(
        Method $method,
        string $target,
        array $headers = [],
        string $body = '',
        array $cookies = [],
    ): ServerRequest {
        $parsedHeaders = Headers::fromArray($headers);

        return new ServerRequest(
            $method,
            Uri::fromRequestTarget($target, 'http', 'localhost', 8080),
            $parsedHeaders,
            $body,
            $cookies,
            form: FormBody::parse($parsedHeaders, $body),
        );
    }

    public static function get(string $target): ServerRequest
    {
        return self::of(Method::Get, $target);
    }

    public static function post(string $target, string $body = ''): ServerRequest
    {
        return self::of(Method::Post, $target, body: $body);
    }

    /**
     * A POST carrying form fields, encoded the way a browser would.
     *
     * @param array<string, string> $fields
     * @param array<string, string> $cookies
     */
    public static function form(string $target, array $fields, array $cookies = []): ServerRequest
    {
        return self::of(
            Method::Post,
            $target,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($fields),
            $cookies,
        );
    }
}
