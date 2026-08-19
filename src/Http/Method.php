<?php

declare(strict_types=1);

namespace PhpOrbit\Http;

use PhpOrbit\Http\Exception\MalformedRequest;

enum Method: string
{
    case Get = 'GET';
    case Head = 'HEAD';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Options = 'OPTIONS';

    public static function parse(string $raw): self
    {
        return self::tryFrom(strtoupper($raw))
            ?? throw new MalformedRequest(sprintf('Unsupported HTTP method "%s".', $raw));
    }

    /**
     * Whether this method may change server state.
     *
     * CSRF protection keys off this: safe methods are exempt, everything
     * else requires a valid token unless a route opts out explicitly.
     */
    public function isStateChanging(): bool
    {
        return match ($this) {
            self::Get, self::Head, self::Options => false,
            self::Post, self::Put, self::Patch, self::Delete => true,
        };
    }
}
