<?php

declare(strict_types=1);

namespace PhpOrbit\Api;

use Closure;
use InvalidArgumentException;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Routing\RouteCollection;
use RuntimeException;

/**
 * Loads editable app/api/vN/*.php declarations once, during boot.
 */
final class RouteFiles
{
    public static function register(RouteCollection $routes, string $directory): void
    {
        $document = self::load($routes, $directory);
        if ($document === null) {
            return;
        }
        $patterns = [];
        foreach ($routes->compile()->routes() as $route) {
            if ($route->method === Method::Get) {
                $patterns[] = $route->pattern;
            }
        }
        if (!in_array('/api/openapi.json', $patterns, true)) {
            $routes->get('/api/openapi.json', static fn (): Response => Response::json($document->document()), 'api.openapi');
        }
        if (!in_array('/api/docs', $patterns, true)) {
            $routes->get('/api/docs', SwaggerController::class, 'api.docs');
        }
    }

    public static function document(string $directory, ?OpenApi $base = null): OpenApi
    {
        $extra = self::load(new RouteCollection(), $directory);
        if ($extra === null) {
            return $base ?? throw new InvalidArgumentException('No API declarations found.');
        }
        if ($base === null) {
            return $extra;
        }

        return self::merge($base, $extra);
    }

    private static function load(RouteCollection $routes, string $directory): ?OpenApi
    {
        $files = glob($directory . '/v*/*.php');
        if ($files === false) {
            throw new RuntimeException('Cannot enumerate API declarations in ' . $directory);
        }
        sort($files, SORT_STRING);
        /** @var array<int, VersionedRoutes> $versions */
        $versions = [];
        foreach ($files as $file) {
            $version = filter_var(substr(basename(dirname($file)), 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($version === false) {
                throw new InvalidArgumentException('API declaration directories must be named v1, v2, ...: ' . $file);
            }
            /** @var mixed $define */
            $define = require $file;
            if (!$define instanceof Closure) {
                throw new InvalidArgumentException('API declaration must return a closure taking VersionedRoutes: ' . $file);
            }
            $api = $versions[$version] ??= new VersionedRoutes($routes, $version);
            $define($api);
        }
        $document = null;
        foreach ($versions as $api) {
            $next = $api->document();
            $document = $document === null ? $next : self::merge($document, $next);
        }

        return $document;
    }

    private static function merge(OpenApi $base, OpenApi $extra): OpenApi
    {
        $document = $base->document();
        $paths = $document['paths'];
        $additional = $extra->document()['paths'];
        if (!is_array($paths) || !is_array($additional)) {
            throw new InvalidArgumentException('OpenAPI paths must be an object.');
        }
        foreach ($additional as $path => $operations) {
            if (!is_array($operations)) {
                throw new InvalidArgumentException('OpenAPI path operations must be objects.');
            }
            $existing = $paths[$path] ?? [];
            if (!is_array($existing) || array_intersect_key($existing, $operations) !== []) {
                throw new InvalidArgumentException('Duplicate OpenAPI operation at ' . $path);
            }
            $paths[$path] = [...$existing, ...$operations];
        }
        $document['paths'] = $paths;

        return new OpenApi($document);
    }
}
