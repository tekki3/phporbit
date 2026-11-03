<?php

declare(strict_types=1);

namespace PhpOrbit\Cache;

use Redis;
use RedisException;

final class RedisCacheStore implements CacheStore
{
    private readonly string $prefix;

    public function __construct(
        private readonly Redis $client,
        string $namespace = 'phporbit',
    ) {
        $this->prefix = 'orbit:' . hash('sha256', $namespace) . ':';
    }

    public function get(string $key): ?string
    {
        try {
            $this->client->clearLastError();
            $value = $this->client->get($this->prefix . hash('sha256', $key));
        } catch (RedisException $error) {
            throw new CacheFailed('Redis cache read failed.', 0, $error);
        }

        if ($value === false) {
            if ($this->client->getLastError() !== null) {
                throw new CacheFailed('Redis cache read failed.');
            }

            return null;
        }

        if (!is_string($value)) {
            throw new CacheFailed('Redis returned an invalid cache value.');
        }

        return $value;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        Cache::validateTtl($ttlSeconds);

        try {
            $result = $this->client->set(
                $this->prefix . hash('sha256', $key),
                $value,
                $ttlSeconds === 0 ? [] : ['ex' => $ttlSeconds],
            );
        } catch (RedisException $error) {
            throw new CacheFailed('Redis cache write failed.', 0, $error);
        }

        if ($result !== true) {
            throw new CacheFailed('Redis refused the cache write.');
        }
    }

    public function delete(string $key): void
    {
        try {
            if ($this->client->del($this->prefix . hash('sha256', $key)) === false) {
                throw new CacheFailed('Redis refused the cache deletion.');
            }
        } catch (RedisException $error) {
            throw new CacheFailed('Redis cache deletion failed.', 0, $error);
        }
    }

    public function clear(): void
    {
        $cursor = null;

        try {
            do {
                $this->client->clearLastError();
                $keys = $this->client->scan($cursor, $this->prefix . '*', 100);

                if ($keys === false && ($this->client->getLastError() !== null || $cursor === null)) {
                    throw new CacheFailed('Redis cache scan failed.');
                }

                if (is_array($keys)) {
                    foreach ($keys as $key) {
                        if ($this->client->del($key) === false) {
                            throw new CacheFailed('Redis refused the cache deletion.');
                        }
                    }
                }
            } while ($cursor !== 0);
        } catch (RedisException $error) {
            throw new CacheFailed('Redis cache clear failed.', 0, $error);
        }
    }
}
