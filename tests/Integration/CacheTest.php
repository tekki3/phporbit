<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Integration;

use Memcached;
use PhpOrbit\Cache\CacheFactory;
use PhpOrbit\Cache\CacheFailed;
use PhpOrbit\Config\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    use RequiresService;

    #[DataProvider('drivers')]
    public function test_remote_cache_contract(string $driver, string $environmentPrefix, int $defaultPort): void
    {
        if (!extension_loaded($driver)) {
            self::markTestSkipped('Requires ext-' . $driver . '.');
        }

        $settings = $this->requireEnvironment([$environmentPrefix . '_HOST'], $driver);
        $host = $settings[$environmentPrefix . '_HOST'];
        $port = (int) (getenv($environmentPrefix . '_PORT') ?: (string) $defaultPort);
        $this->requireReachable($host, $port, $driver);
        $prefix = 'orbit-test-' . bin2hex(random_bytes(8));
        $config = Environment::fromArray([
            'CACHE_DRIVER' => $driver,
            'CACHE_HOST' => $host,
            'CACHE_PORT' => (string) $port,
            'CACHE_PREFIX' => $prefix,
            'CACHE_TTL' => '1',
            'CACHE_USERNAME' => getenv('REDIS_USERNAME') ?: '',
            'CACHE_PASSWORD' => getenv('REDIS_PASSWORD') ?: '',
        ]);
        $cache = CacheFactory::fromEnvironment($config, __DIR__);
        $peer = CacheFactory::fromEnvironment($config, __DIR__);
        $other = CacheFactory::fromEnvironment($config->with(['CACHE_PREFIX' => $prefix . '-other']), __DIR__);

        try {
            foreach ([null, false, 0, '', 1.0, ['rows' => [['id' => 7, 'active' => true]]]] as $index => $value) {
                $cache->set('value-' . $index, $value, 0);
                self::assertSame($value, $peer->get('value-' . $index));
                self::assertTrue($peer->has('value-' . $index));
                self::assertSame($value, $peer->remember('value-' . $index, static fn (): string => 'not used'));
            }

            $cache->set('long-ttl', 'survives', 31 * 86400);
            self::assertSame('survives', $peer->get('long-ttl'));
            $cache->set('expires', 'short');
            sleep(2);
            self::assertFalse($peer->has('expires'));
            self::assertTrue($peer->has('value-0'));
            self::assertSame('new', $peer->remember('expires', static fn (): string => 'new'));
            $cache->delete('expires');
            $cache->delete('expires');
            self::assertFalse($peer->has('expires'));

            $other->set('value-0', 'unrelated', 0);
            $cache->clear();
            self::assertFalse($peer->has('value-0'));
            self::assertFalse($peer->has('long-ttl'));
            self::assertSame('unrelated', $other->get('value-0'));
            $peer->set('after-clear', 'fresh', 0);
            self::assertSame('fresh', $cache->get('after-clear'));

            if ($driver === 'memcached') {
                $client = new Memcached();
                self::assertTrue($client->addServer($host, $port));
                self::assertTrue($client->delete('orbit:' . hash('sha256', $prefix) . ':generation'));
                self::assertFalse($peer->has('after-clear'));
                $peer->set('after-eviction', 'fresh', 0);
                self::assertSame('fresh', $cache->get('after-eviction'));

                $this->expectException(CacheFailed::class);
                $cache->set('oversized', base64_encode(random_bytes(2 * 1024 * 1024)), 0);
            } else {
                // Force multiple SCAN batches, not just a single small response.
                for ($index = 0; $index < 300; $index++) {
                    $cache->set('bulk-' . $index, $index, 0);
                }

                $peer->clear();

                for ($index = 0; $index < 300; $index++) {
                    self::assertFalse($cache->has('bulk-' . $index));
                }
            }
        } finally {
            $cache->clear();
            $other->clear();
        }
    }

    #[DataProvider('drivers')]
    public function test_unreachable_servers_are_not_cache_misses(string $driver, string $environmentPrefix, int $defaultPort): void
    {
        if (!extension_loaded($driver)) {
            self::markTestSkipped('Requires ext-' . $driver . '.');
        }

        // Reserve, then release a local port rather than assuming a fixed port is free.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $port = substr($address, (int) strrpos($address, ':') + 1);
        $config = Environment::fromArray([
            'CACHE_DRIVER' => $driver,
            'CACHE_HOST' => '127.0.0.1',
            'CACHE_PORT' => $port,
            'CACHE_TIMEOUT' => '1',
        ]);

        $this->expectException(CacheFailed::class);
        CacheFactory::fromEnvironment($config, __DIR__)->get('key');
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function drivers(): iterable
    {
        yield 'redis' => ['redis', 'REDIS', 6379];
        yield 'memcached' => ['memcached', 'MEMCACHED', 11211];
    }
}
