<?php

declare(strict_types=1);

namespace PhpOrbit\Cache;

use Memcached;
use PhpOrbit\Config\Environment;
use PhpOrbit\Config\MissingConfiguration;
use Redis;
use RedisException;

final class CacheFactory
{
    public static function fromEnvironment(Environment $config, string $root): Cache
    {
        $driver = strtolower(trim($config->string('CACHE_DRIVER', 'file')));
        $namespace = $config->has('CACHE_PREFIX')
            ? $config->required('CACHE_PREFIX')
            : hash('sha256', $root);
        $ttl = $config->int('CACHE_TTL', 300);
        Cache::validateTtl($ttl);

        $store = match ($driver) {
            'file', 'storage' => new FileCacheStore(
                $config->path('CACHE_PATH', $root, 'storage/cache/data'),
                $namespace,
            ),
            'redis' => self::redis($config, $namespace),
            'memcached' => self::memcached($config, $namespace),
            default => throw MissingConfiguration::notOfType('CACHE_DRIVER', 'cache driver', 'file, storage, redis, memcached'),
        };

        return new Cache($store, $ttl);
    }

    private static function redis(Environment $config, string $namespace): RedisCacheStore
    {
        if (!class_exists(Redis::class)) {
            throw new CacheFailed('The redis cache driver requires the ext-redis PHP extension.');
        }

        [$host, $port, $timeout] = self::server($config, 6379);
        $database = $config->int('CACHE_DATABASE', 0);

        if ($database < 0) {
            throw MissingConfiguration::notOfType('CACHE_DATABASE', 'Redis database index', 'a non-negative integer');
        }

        $client = new Redis();

        try {
            if (!$client->connect($host, $port, $timeout, read_timeout: $timeout)) {
                throw new CacheFailed('Cannot connect to the Redis cache server.');
            }

            $password = $config->string('CACHE_PASSWORD', '');
            $username = $config->string('CACHE_USERNAME', '');

            if ($username !== '' && $password === '') {
                throw new CacheFailed('CACHE_USERNAME requires CACHE_PASSWORD for Redis authentication.');
            }

            if ($password !== '' && !$client->auth($username === '' ? $password : [$username, $password])) {
                throw new CacheFailed('Redis cache authentication failed.');
            }

            if (!$client->select($database)) {
                throw new CacheFailed('Cannot select the Redis cache database.');
            }
        } catch (RedisException $error) {
            // Do not echo server replies or configured credentials.
            throw new CacheFailed('Cannot initialise the Redis cache connection.', 0, $error);
        }

        return new RedisCacheStore($client, $namespace);
    }

    private static function memcached(Environment $config, string $namespace): MemcachedCacheStore
    {
        if (!class_exists(Memcached::class)) {
            throw new CacheFailed('The memcached cache driver requires the ext-memcached PHP extension.');
        }

        [$host, $port, $timeout] = self::server($config, 11211);
        $client = new Memcached();

        if (!$client->setOption(Memcached::OPT_CONNECT_TIMEOUT, $timeout * 1000)
            || !$client->setOption(Memcached::OPT_POLL_TIMEOUT, $timeout * 1000)
            || !$client->setOption(Memcached::OPT_SEND_TIMEOUT, $timeout * 1000000)
            || !$client->setOption(Memcached::OPT_RECV_TIMEOUT, $timeout * 1000000)
            || !$client->addServer($host, $port)) {
            throw new CacheFailed('Cannot initialise the Memcached cache connection.');
        }

        return new MemcachedCacheStore($client, $namespace);
    }

    /** @return array{string, int, int} */
    private static function server(Environment $config, int $defaultPort): array
    {
        $host = $config->string('CACHE_HOST', '127.0.0.1');
        $port = $config->int('CACHE_PORT', $defaultPort);
        $timeout = $config->int('CACHE_TIMEOUT', 2);

        if ($host === '' || str_contains($host, "\0")) {
            throw MissingConfiguration::notOfType('CACHE_HOST', 'cache host', 'a non-empty hostname or address');
        }

        if ($port < 1 || $port > 65535) {
            throw MissingConfiguration::notOfType('CACHE_PORT', 'cache port', '1 through 65535');
        }

        if ($timeout < 1 || $timeout > 60) {
            throw MissingConfiguration::notOfType('CACHE_TIMEOUT', 'cache timeout', '1 through 60 seconds');
        }

        return [$host, $port, $timeout];
    }
}
