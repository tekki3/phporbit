<?php

declare(strict_types=1);

namespace PhpOrbit\Api;

use Closure;
use InvalidArgumentException;
use PhpOrbit\Http\Method;
use PhpOrbit\Routing\RouteCollection;

/**
 * Boot-only declarations drive routing and documentation together.
 *
 * @phpstan-import-type RouteHandler from RouteCollection
 */
final class VersionedRoutes
{
    /** @var array<string, array<string, mixed>> */
    private array $paths = [];

    public function __construct(
        private readonly RouteCollection $routes,
        private readonly int $version = 1,
        private readonly string $title = 'Application API',
    ) {
        if ($version < 1) {
            throw new InvalidArgumentException('API versions must be positive integers.');
        }
    }

    /**
     * @param RouteHandler $handler
     * @param array<string, mixed> $operation OpenAPI operation, excluding security.
     * @param list<string> $scopes
     */
    public function add(
        Method $method, string $path, Closure|string $handler, string $name, array $operation,
        bool $authenticated = true, array $scopes = [], int $limit = 60,
    ): self {
        if (!str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException('API paths must start with / and contain no query or fragment.');
        }
        $prefix = '/api/v' . $this->version;
        $pattern = $prefix . $path;
        $openPath = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*):[^}]+\}/', '{$1}', $pattern);
        if ($openPath === null || isset($this->paths[$openPath][strtolower($method->value)])) {
            throw new InvalidArgumentException('Duplicate or invalid API operation.');
        }
        $operation['operationId'] = $name;
        $operation['security'] = $authenticated ? [['bearerAuth' => []]] : [];
        if ($scopes !== []) {
            $operation['x-required-scopes'] = $scopes;
        }
        if (!isset($operation['responses'])) {
            throw new InvalidArgumentException('OpenAPI operations must declare their responses.');
        }
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $openPath, $parameters);
        $declared = $operation['parameters'] ?? [];
        if (!is_array($declared) || !array_is_list($declared)) {
            throw new InvalidArgumentException('OpenAPI parameters must be a list.');
        }
        foreach ($parameters[1] as $parameter) {
            $present = false;
            foreach ($declared as $entry) {
                if (is_array($entry) && ($entry['name'] ?? null) === $parameter && ($entry['in'] ?? null) === 'path') {
                    $present = true;
                    break;
                }
            }
            if (!$present) {
                $declared[] = ['name' => $parameter, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
            }
        }
        $operation['parameters'] = $declared;
        json_encode($operation, JSON_THROW_ON_ERROR);
        $middleware = $authenticated ? [new RequireBearer($scopes)] : [];
        $middleware[] = new RateLimit('api:v' . $this->version . ':' . $name, $limit);
        $this->routes->add($method, $pattern, $handler, $name, csrfExempt: true, middleware: $middleware);
        $this->paths[$openPath][strtolower($method->value)] = $operation;

        return $this;
    }

    public function document(): OpenApi
    {
        if ($this->paths === []) {
            throw new InvalidArgumentException('Declare at least one API operation before building its document.');
        }
        return new OpenApi([
            'openapi' => '3.0.3', 'info' => ['title' => $this->title, 'version' => (string) $this->version],
            'paths' => $this->paths,
            'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer',
                'description' => 'JWT access token or personal API token.']]],
        ]);
    }
}
