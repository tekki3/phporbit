<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Api;

use FilesystemIterator;
use InvalidArgumentException;
use PhpOrbit\Api\RouteFiles;
use PhpOrbit\Api\VersionedRoutes;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Routing\RouteCollection;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class RouteFilesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/orbit-api-files-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/v1', 0o755, true);
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item instanceof SplFileInfo) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }
        rmdir($this->directory);
    }

    public function test_missing_directories_do_not_change_existing_routes(): void
    {
        $routes = new RouteCollection();
        $routes->get('/', static fn (): Response => Response::text('home'));
        RouteFiles::register($routes, $this->directory . '/absent');
        self::assertCount(1, $routes->compile()->routes());
    }

    public function test_documentation_includes_all_files_without_replacing_existing_documentation_routes(): void
    {
        $this->write();
        $routes = new RouteCollection();
        $routes->get('/api/docs', static fn (): Response => Response::text('custom'), 'custom.docs');
        RouteFiles::register($routes, $this->directory);
        $router = $routes->compile();
        self::assertCount(3, $router->routes());
        self::assertTrue($router->hasName('custom.docs'));
        self::assertFalse($router->hasName('api.docs'));
        $paths = RouteFiles::document($this->directory)->document()['paths'];
        self::assertIsArray($paths);
        self::assertArrayHasKey('/api/v1/ping', $paths);
    }

    public function test_a_malformed_file_stops_boot_with_a_useful_error(): void
    {
        file_put_contents($this->directory . '/v1/ping.php', '<?php return [];');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('closure taking VersionedRoutes');
        RouteFiles::register(new RouteCollection(), $this->directory);
    }

    public function test_existing_route_collisions_are_reported_instead_of_shadowed(): void
    {
        $this->write();
        $routes = new RouteCollection();
        $routes->get('/api/v1/ping', static fn (): Response => Response::text('existing'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate API route');
        RouteFiles::register($routes, $this->directory);
    }

    public function test_manual_and_generated_openapi_operations_are_combined(): void
    {
        $this->write();
        $base = new VersionedRoutes(new RouteCollection());
        $base->add(Method::Get, '/manual', static fn (): Response => Response::json([]), 'manual', [
            'responses' => ['200' => ['description' => 'A response']],
        ]);
        $paths = RouteFiles::document($this->directory, $base->document())->document()['paths'];
        self::assertIsArray($paths);
        self::assertArrayHasKey('/api/v1/manual', $paths);
        self::assertArrayHasKey('/api/v1/ping', $paths);
    }

    private function write(): void
    {
        file_put_contents($this->directory . '/v1/ping.php', <<<'PHP'
            <?php
            use PhpOrbit\Api\VersionedRoutes;
            use PhpOrbit\Http\Method;
            use PhpOrbit\Http\Response;
            return static function (VersionedRoutes $api): void {
                $api->add(Method::Get, '/ping', static fn (): Response => Response::json(['ok' => true]), 'ping',
                    ['responses' => ['200' => ['description' => 'A response']]], authenticated: false);
            };
            PHP);
    }
}
