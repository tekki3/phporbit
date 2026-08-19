<?php

declare(strict_types=1);

namespace PhpOrbit\Http;

use PhpOrbit\Http\Exception\MalformedRequest;

final class Uri
{
    /** @var array<string, string> */
    private readonly array $query;

    /**
     * @param array<string, string> $query
     */
    private function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly string $path,
        array $query,
    ) {
        $this->query = $query;
    }

    /**
     * Builds a URI from a request target (`/path?query`) plus connection facts.
     */
    public static function fromRequestTarget(
        string $target,
        string $scheme,
        string $host,
        int $port,
    ): self {
        // parse_url() silently rewrites a NUL to "_", so the raw bytes must be
        // checked before it runs or the byte would slip through unnoticed.
        if (str_contains($target, "\0")) {
            throw new MalformedRequest('Request target may not contain NUL.');
        }

        $parts = parse_url($target);

        if ($parts === false) {
            throw new MalformedRequest(sprintf('Unparsable request target "%s".', $target));
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $parsed);
            /** @var array<string, string> $query */
            $query = array_filter($parsed, is_string(...));
        }

        return new self(
            $scheme,
            $host,
            $port,
            self::normalisePath($parts['path'] ?? '/'),
            $query,
        );
    }

    public function withPath(string $path): self
    {
        return new self($this->scheme, $this->host, $this->port, self::normalisePath($path), $this->query);
    }

    public function queryParam(string $name): ?string
    {
        return $this->query[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function queryParams(): array
    {
        return $this->query;
    }

    public function isSecure(): bool
    {
        return $this->scheme === 'https';
    }

    public function authority(): string
    {
        $isDefaultPort = ($this->scheme === 'https' && $this->port === 443)
            || ($this->scheme === 'http' && $this->port === 80);

        return $isDefaultPort ? $this->host : $this->host . ':' . $this->port;
    }

    public function __toString(): string
    {
        $uri = $this->scheme . '://' . $this->authority() . $this->path;

        return $this->query === [] ? $uri : $uri . '?' . http_build_query($this->query);
    }

    /**
     * Percent-decodes the path and resolves `.` and `..` before routing sees it.
     *
     * Order matters throughout. The path is split on `/` *before* decoding, so
     * an encoded separator can never introduce a new segment. Dot segments are
     * then matched *after* decoding, so `%2E%2E` is caught as traversal rather
     * than passed through as an ordinary segment name. A path that climbs above
     * the root is rejected rather than clamped, since it can only be hostile.
     */
    private static function normalisePath(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $resolved = [];
        foreach (explode('/', $path) as $rawSegment) {
            $segment = rawurldecode($rawSegment);

            if (str_contains($segment, "\0")) {
                throw new MalformedRequest('Request path may not contain NUL.');
            }

            // A decoded segment containing a separator is ambiguous: it cannot
            // be re-joined without changing the path's structure. Rejecting is
            // what nginx and Apache do with %2F by default.
            if (str_contains($segment, '/')) {
                throw new MalformedRequest('Encoded path separators are not accepted.');
            }

            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment !== '..') {
                $resolved[] = $segment;
                continue;
            }

            if ($resolved === []) {
                throw new MalformedRequest('Request path escapes the document root.');
            }

            array_pop($resolved);
        }

        $normalised = '/' . implode('/', $resolved);

        // Preserve a meaningful trailing slash, but never produce "//".
        if ($normalised !== '/' && str_ends_with($path, '/')) {
            $normalised .= '/';
        }

        return $normalised;
    }
}
