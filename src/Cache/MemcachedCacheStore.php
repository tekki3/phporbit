<?php

declare(strict_types=1);

namespace PhpOrbit\Cache;

use Memcached;

final class MemcachedCacheStore implements CacheStore
{
    private readonly string $prefix;

    public function __construct(
        private readonly Memcached $client,
        string $namespace = 'phporbit',
    ) {
        $this->prefix = 'orbit:' . hash('sha256', $namespace) . ':';
    }

    public function get(string $key): ?string
    {
        $value = $this->client->get($this->key($key));
        $code = $this->client->getResultCode();

        if ($code === Memcached::RES_NOTFOUND) {
            return null;
        }

        if ($code !== Memcached::RES_SUCCESS || !is_string($value)) {
            throw new CacheFailed('Memcached cache read failed.');
        }

        return $value;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        Cache::validateTtl($ttlSeconds);
        // Memcached interprets values above 30 days as absolute timestamps.
        $expiration = $ttlSeconds > 2592000 ? time() + $ttlSeconds : $ttlSeconds;

        if (!$this->client->set($this->key($key), $value, $expiration)) {
            throw new CacheFailed('Memcached cache write failed.');
        }
    }

    public function delete(string $key): void
    {
        if (!$this->client->delete($this->key($key)) && $this->client->getResultCode() !== Memcached::RES_NOTFOUND) {
            throw new CacheFailed('Memcached cache deletion failed.');
        }
    }

    public function clear(): void
    {
        // A server-wide flush would destroy unrelated applications' caches.
        if (!$this->client->set($this->prefix . 'generation', bin2hex(random_bytes(16)))) {
            throw new CacheFailed('Memcached cache clear failed.');
        }
    }

    private function key(string $key): string
    {
        $generationKey = $this->prefix . 'generation';

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $generation = $this->client->get($generationKey);
            $code = $this->client->getResultCode();

            if ($code === Memcached::RES_SUCCESS && is_string($generation)
                && preg_match('/^[a-f0-9]{32}$/D', $generation) === 1) {
                return $this->prefix . $generation . ':' . hash('sha256', $key);
            }

            if ($code !== Memcached::RES_NOTFOUND) {
                throw new CacheFailed('Cannot read the Memcached cache namespace.');
            }

            $generation = bin2hex(random_bytes(16));

            if ($this->client->add($generationKey, $generation)) {
                return $this->prefix . $generation . ':' . hash('sha256', $key);
            }

            if (!in_array($this->client->getResultCode(), [Memcached::RES_NOTSTORED, Memcached::RES_DATA_EXISTS], true)) {
                throw new CacheFailed('Cannot initialise the Memcached cache namespace.');
            }
        }

        throw new CacheFailed('The Memcached cache namespace changed too often.');
    }
}
