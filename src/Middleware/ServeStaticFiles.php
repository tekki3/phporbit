<?php

declare(strict_types=1);

namespace PhpOrbit\Middleware;

use Closure;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use RuntimeException;

/**
 * Serves files from a directory, falling through to the application.
 *
 * Written as middleware rather than as part of the built-in server so it also
 * works under FrankenPHP. Behind nginx or Apache it is effectively dead code:
 * those serve real files before PHP is ever invoked, which is faster and is
 * why the front controllers check `-f` first.
 *
 * Path safety rests on two independent checks. The request path has already
 * had its dot segments resolved and encoded separators rejected by
 * {@see \PhpOrbit\Http\Uri}; this then resolves the candidate with `realpath()`
 * and confirms the result is still inside the root, which also catches a
 * symlink pointing out of it.
 */
final class ServeStaticFiles implements Middleware
{
    /** @var array<string, string> */
    private const MIME_TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'webmanifest' => 'application/manifest+json',
        'html' => 'text/html; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'pdf' => 'application/pdf',
        'xml' => 'application/xml',
        'csv' => 'text/csv; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'zip' => 'application/zip',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'wasm' => 'application/wasm',
    ];

    private readonly string $root;

    private readonly string $prefix;

    /**
     * @param string $prefix URL prefix this root answers for, e.g. "/docs".
     *                       Empty means the whole path space.
     */
    public function __construct(
        string $root,
        private readonly int $maxAgeSeconds = 0,
        string $prefix = '',
        private readonly string $directoryIndex = 'index.html',
    ) {
        $resolved = realpath($root);

        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException(sprintf('Static file root "%s" does not exist.', $root));
        }

        $this->root = $resolved;
        $this->prefix = $prefix === '' ? '' : '/' . trim($prefix, '/');
    }

    public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
    {
        if ($request->method !== Method::Get && $request->method !== Method::Head) {
            return $next($request);
        }

        $path = $this->resolve($request->uri->path);

        if ($path === null) {
            return $next($request);
        }

        return $this->serve($request, $path);
    }

    /**
     * Maps a request path to a real file, or null if it is not one.
     */
    private function resolve(string $requestPath): ?string
    {
        if (str_contains($requestPath, "\0")) {
            return null;
        }

        $relative = $this->stripPrefix($requestPath);

        if ($relative === null) {
            return null;
        }

        // Hidden files are never served: .env, .git and friends live next to
        // the code and are exactly what an attacker probes for first.
        foreach (explode('/', $relative) as $segment) {
            if (str_starts_with($segment, '.')) {
                return null;
            }
        }

        $candidate = realpath($this->root . $relative);

        if ($candidate === false) {
            return null;
        }

        // "/docs" and "/docs/" should both land on the folder's index page.
        if (is_dir($candidate)) {
            $candidate = realpath($candidate . DIRECTORY_SEPARATOR . $this->directoryIndex);

            if ($candidate === false) {
                return null;
            }
        }

        if (!is_file($candidate)) {
            return null;
        }

        // Not a type we serve — including every flavour of source code. Falls
        // through to the router rather than 404ing here, so an application may
        // still answer the path itself.
        if ($this->mimeType($candidate) === null) {
            return null;
        }

        // The decisive check: after following symlinks, is it still inside?
        if ($candidate !== $this->root && !str_starts_with($candidate, $this->root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $candidate;
    }

    /**
     * Removes the mount prefix, or returns null when this root is not responsible.
     *
     * A prefixed instance answers for "/docs" and "/docs/..." but must not be
     * fooled by "/docsomething", which is why the boundary is checked rather
     * than a bare str_starts_with on the prefix alone.
     *
     * @return string|null the path relative to the root, beginning with "/"
     */
    private function stripPrefix(string $requestPath): ?string
    {
        if ($this->prefix === '') {
            return $requestPath === '/' ? null : $requestPath;
        }

        if ($requestPath === $this->prefix) {
            return '/';
        }

        if (!str_starts_with($requestPath, $this->prefix . '/')) {
            return null;
        }

        return substr($requestPath, strlen($this->prefix));
    }

    private function serve(ServerRequest $request, string $path): Response
    {
        $modified = filemtime($path);
        $size = filesize($path);

        // The file existed a moment ago in resolve(); losing it here means it
        // was removed mid-request, which is a 404 like any other missing file.
        if ($modified === false || $size === false) {
            return Response::text('Not Found', Status::NotFound);
        }

        // Weak validator built from the facts a client needs to detect change.
        $etag = sprintf('W/"%x-%x"', $modified, $size);

        if ($request->headers->first('If-None-Match') === $etag) {
            return Response::make(Status::NotModified)->withHeader('ETag', $etag);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return Response::text('Not Found', Status::NotFound);
        }

        $response = Response::make(Status::Ok, $contents)
            // resolve() already refused anything without a known type.
            ->withHeader('Content-Type', $this->mimeType($path) ?? 'application/octet-stream')
            ->withHeader('ETag', $etag)
            ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s T', $modified));

        return $this->maxAgeSeconds > 0
            ? $response->withHeader('Cache-Control', 'public, max-age=' . $this->maxAgeSeconds)
            : $response->withHeader('Cache-Control', 'no-cache');
    }

    /**
     * The media type for a path, or null when the extension is not on the list.
     *
     * An allowlist rather than a fallback to `application/octet-stream`. The
     * fallback looked harmless — an unknown type is merely downloaded — but it
     * meant this middleware would hand out the source of any file under its
     * root, including `public/index.php` and anything else with a `.php`
     * extension. A server whose job is to return files verbatim must never be
     * pointed at code, and the only reliable way to guarantee that is to serve
     * nothing it was not told about.
     *
     * Add an entry to {@see MIME_TYPES} when you need a type that is missing;
     * the failure mode is a 404, which is discoverable and safe.
     */
    private function mimeType(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::MIME_TYPES[$extension] ?? null;
    }
}
