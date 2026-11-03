<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Worker;

use PhpOrbit\Cache\Cache;
use PhpOrbit\Cache\FileCacheStore;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    public function test_worker_shares_only_explicitly_keyed_json_and_sees_external_updates(): void
    {
        $directory = sys_get_temp_dir() . '/orbit-worker-cache-' . bin2hex(random_bytes(6));
        $cache = new Cache(new FileCacheStore($directory));
        $app = Application::boot(static function (Blueprint $app) use ($cache): void {
            $app->container->singleton(Cache::class, static fn (): Cache => $cache);
            $app->routes->get('/{visitor}', static function (ServerRequest $request, RequestScope $scope): Response {
                $visitor = $request->attribute('visitor');
                self::assertIsString($visitor);
                $cache = $scope->get(Cache::class);
                $value = $cache->remember('visitor:' . $visitor, static fn (): array => ['visitor' => $visitor]);

                return Response::json(['cached' => $value]);
            });
        });

        try {
            self::assertSame('{"cached":{"visitor":"alice"}}', $app->handle(Requests::get('/alice'))->body);
            self::assertSame('{"cached":{"visitor":"bob"}}', $app->handle(Requests::get('/bob'))->body);
            $external = new Cache(new FileCacheStore($directory));
            $external->set('visitor:alice', ['visitor' => 'updated']);
            self::assertSame('{"cached":{"visitor":"updated"}}', $app->handle(Requests::get('/alice'))->body);
            $local = $cache->get('visitor:bob');
            self::assertIsArray($local);
            $local['visitor'] = 'mutated locally';
            self::assertSame('{"cached":{"visitor":"bob"}}', $app->handle(Requests::get('/bob'))->body);
            $external->clear();
            self::assertSame('{"cached":{"visitor":"alice"}}', $app->handle(Requests::get('/alice'))->body);
        } finally {
            $cache->clear();
            rmdir($directory);
        }
    }
}
