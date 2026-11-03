<?php

declare(strict_types=1);

namespace PhpOrbit\Cache;

/**
 * Stores encoded values. A miss is null; operational failures throw CacheFailed.
 */
interface CacheStore
{
    public function get(string $key): ?string;

    /** Zero means no expiration. */
    public function set(string $key, string $value, int $ttlSeconds): void;

    public function delete(string $key): void;

    /** Clears only this store's namespace, never the entire server. */
    public function clear(): void;
}
