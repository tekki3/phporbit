<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use InvalidArgumentException;
use PhpOrbit\Http\Method;
use RuntimeException;
use Throwable;

final class ApiMaker
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = realpath($root) ?: throw new RuntimeException('The project directory does not exist.');
    }

    public function create(string $name, Method $method = Method::Get, int $version = 1, bool $public = false): GeneratedApi
    {
        if (!is_file($this->root . '/app/bootstrap.php')) {
            throw new RuntimeException('Run make:api inside a phporbit project.');
        }
        if ($version < 1 || $version > 999 || !in_array($method, [Method::Get, Method::Post, Method::Put, Method::Patch, Method::Delete], true)) {
            throw new InvalidArgumentException('Use version 1–999 and GET, POST, PUT, PATCH or DELETE.');
        }
        $segments = PhpName::segments($name, 'API', 'Products, CreateProduct, Admin/Report');
        $last = array_key_last($segments);
        $segments[$last] = str_ends_with($segments[$last], 'Controller') ? substr($segments[$last], 0, -10) : $segments[$last];
        if ($segments[$last] === '') {
            throw new InvalidArgumentException('An API name must contain more than Controller.');
        }
        $slug = array_map(PhpName::toKebabCase(...), $segments);
        $path = '/' . implode('/', $slug);
        $class = array_pop($segments) . 'Controller';
        $namespace = implode('\\', ['App', 'Controllers', 'Api', 'V' . $version, ...$segments]);
        $controller = implode('/', ['app', 'src', 'Controllers', 'Api', 'V' . $version, ...$segments, $class . '.php']);
        $declaration = 'app/api/v' . $version . '/' . implode('.', $slug) . '.php';
        $routeName = 'api.v' . $version . '.' . implode('.', $slug);
        $files = [
            $controller => $this->controller($namespace, $class),
            $declaration => $this->declaration($namespace . '\\' . $class, $method, $path, $routeName, $public),
        ];
        if ((glob($this->root . '/database/migrations/*_create_api.php') ?: []) === []
            && (glob($this->root . '/database/migrations/*_create_auth_kit.php') ?: []) === []) {
            $files['database/migrations/' . gmdate('YmdHis') . '_create_api.php'] = <<<'PHP'
                <?php

                declare(strict_types=1);

                return new \PhpOrbit\Api\ApiMigration();

                PHP;
        }
        $framework = dirname(__DIR__, 2);
        foreach ([
            'swagger/swagger-ui-bundle.js', 'swagger/swagger-ui.css', 'swagger/LICENSE', 'swagger/NOTICE',
            'js/swagger.js', 'css/swagger-shell.css',
        ] as $asset) {
            $relative = 'public/assets/' . $asset;
            $this->assertSafeTarget($relative);
            if (!file_exists($this->root . '/' . $relative)) {
                $contents = file_get_contents($framework . '/' . $relative);
                if ($contents === false) {
                    throw new RuntimeException('Cannot read ' . $relative);
                }
                $files[$relative] = $contents;
            } elseif (!is_file($this->root . '/' . $relative)) {
                throw new RuntimeException('Expected an asset file at ' . $relative);
            }
        }
        foreach (array_keys($files) as $relative) {
            $this->assertSafeTarget($relative);
            if (file_exists($this->root . '/' . $relative)) {
                throw new RuntimeException('Refusing to overwrite ' . $relative . '. No files were generated.');
            }
        }
        $created = [];
        try {
            foreach ($files as $relative => $contents) {
                $target = $this->root . '/' . $relative;
                $directory = dirname($target);
                if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
                    throw new RuntimeException('Cannot create ' . $directory);
                }
                $stream = fopen($target, 'x');
                if ($stream === false) {
                    throw new RuntimeException('Cannot create ' . $relative);
                }
                $created[] = $relative;
                try {
                    if (fwrite($stream, $contents) !== strlen($contents)) {
                        throw new RuntimeException('Cannot finish writing ' . $relative);
                    }
                } finally {
                    fclose($stream);
                }
            }
        } catch (Throwable $error) {
            foreach (array_reverse($created) as $relative) {
                if (!unlink($this->root . '/' . $relative)) {
                    throw new RuntimeException('Generation failed and cleanup could not remove ' . $relative, previous: $error);
                }
            }
            throw $error;
        }

        return new GeneratedApi($controller, $declaration, '/api/v' . $version . $path, $created);
    }

    private function assertSafeTarget(string $relative): void
    {
        for ($path = $this->root . '/' . $relative; $path !== $this->root; $path = dirname($path)) {
            if (is_link($path) || (file_exists($path) && $path !== $this->root . '/' . $relative && !is_dir($path))) {
                throw new RuntimeException('Unsafe generator path ' . $path);
            }
        }
    }

    private function controller(string $namespace, string $class): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use PhpOrbit\Http\Response;
            use PhpOrbit\Http\ServerRequest;
            use PhpOrbit\Routing\Handler;

            final class {$class} implements Handler
            {
                public function handle(ServerRequest \$request): Response
                {
                    return Response::json(['message' => 'It works']);
                }
            }

            PHP;
    }

    private function declaration(string $controller, Method $method, string $path, string $name, bool $public): string
    {
        $authentication = $public ? 'false' : 'true';
        return <<<PHP
            <?php

            declare(strict_types=1);

            use {$controller};
            use PhpOrbit\Api\VersionedRoutes;
            use PhpOrbit\Http\Method;

            return static function (VersionedRoutes \$api): void {
                \$api->add(Method::{$method->name}, '{$path}', {$this->shortName($controller)}::class, '{$name}', [
                    'summary' => '{$method->value} {$path}',
                    'responses' => [
                        '200' => ['description' => 'Successful response', 'content' => [
                            'application/json' => ['schema' => [
                                'type' => 'object',
                                'properties' => ['message' => ['type' => 'string']],
                            ]],
                        ]],
                        '401' => ['description' => 'Bearer authentication required'],
                        '429' => ['description' => 'Rate limit exceeded'],
                    ],
                ], authenticated: {$authentication});
            };

            PHP;
    }

    private function shortName(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }
}
