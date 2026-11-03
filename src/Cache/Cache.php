<?php

declare(strict_types=1);

namespace PhpOrbit\Cache;

use Closure;
use InvalidArgumentException;
use JsonException;

/**
 * JSON is the value boundary: arrays are validated recursively before encoding,
 * and no PHP objects are ever serialised or instantiated on a cache read.
 *
 * @phpstan-type Value scalar|array<array-key, mixed>|null
 */
final class Cache
{
    public function __construct(
        private readonly CacheStore $store,
        private readonly int $defaultTtlSeconds = 300,
    ) {
        self::validateTtl($defaultTtlSeconds);
    }

    /** @return Value */
    public function get(string $key): string|int|float|bool|array|null
    {
        $raw = $this->store->get($this->key($key));

        return $raw === null ? null : $this->decode($raw);
    }

    public function has(string $key): bool
    {
        $raw = $this->store->get($this->key($key));

        if ($raw === null) {
            return false;
        }

        $this->decode($raw);

        return true;
    }

    /** @param Value $value */
    public function set(string $key, string|int|float|bool|array|null $value, ?int $ttlSeconds = null): void
    {
        $key = $this->key($key);
        $ttlSeconds ??= $this->defaultTtlSeconds;
        self::validateTtl($ttlSeconds);
        $this->validateValue($value);

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Cache values must be valid JSON data.', 0, $error);
        }

        $this->store->set($key, $encoded, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        $this->store->delete($this->key($key));
    }

    public function clear(): void
    {
        $this->store->clear();
    }

    /**
     * Cached null is a hit; the callback runs only on a miss.
     * Concurrent misses can compute more than once; this is not a distributed lock.
     *
     * @param Closure(): Value $compute
     * @return Value
     */
    public function remember(string $key, Closure $compute, ?int $ttlSeconds = null): string|int|float|bool|array|null
    {
        self::validateTtl($ttlSeconds ?? $this->defaultTtlSeconds);
        $raw = $this->store->get($this->key($key));

        if ($raw !== null) {
            return $this->decode($raw);
        }

        $value = $compute();
        $this->set($key, $value, $ttlSeconds);

        return $value;
    }

    public static function validateTtl(int $ttlSeconds): void
    {
        if ($ttlSeconds < 0 || ($ttlSeconds > 0 && $ttlSeconds > 2147483647 - time())) {
            throw new InvalidArgumentException('Cache TTL must be non-negative and expire before the Unix timestamp limit (2038).');
        }
    }

    private function key(string $key): string
    {
        if ($key === '' || strlen($key) > 1024) {
            throw new InvalidArgumentException('Cache keys must contain between 1 and 1024 bytes.');
        }

        return hash('sha256', $key);
    }

    /** @return Value */
    private function decode(string $raw): string|int|float|bool|array|null
    {
        try {
            /** @var Value $value */
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $this->validateValue($value);
        } catch (JsonException | InvalidArgumentException $error) {
            throw new CacheFailed('The cached value is not valid JSON.', 0, $error);
        }

        return $value;
    }

    private function validateValue(mixed $value, int $depth = 0): void
    {
        if ($depth >= 128) {
            throw new InvalidArgumentException('Cache values cannot be recursive or nested more than 128 levels.');
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->validateValue($item, $depth + 1);
            }

            return;
        }

        if ($value !== null && !is_scalar($value)) {
            throw new InvalidArgumentException('Cache values can contain only scalars, null and arrays, not objects or resources.');
        }

        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('Cache numbers must be finite.');
        }
    }
}
