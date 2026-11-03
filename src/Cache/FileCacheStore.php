<?php

declare(strict_types=1);

namespace PhpOrbit\Cache;

use FilesystemIterator;
use JsonException;
use UnexpectedValueException;

final class FileCacheStore implements CacheStore
{
    private readonly string $prefix;

    public function __construct(
        private readonly string $directory,
        string $namespace = 'phporbit',
    ) {
        $this->prefix = hash('sha256', $namespace) . '_';

        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new CacheFailed('Cannot create the file cache directory.');
        }

        if (!is_writable($directory)) {
            throw new CacheFailed('The file cache directory is not writable.');
        }
    }

    public function get(string $key): ?string
    {
        $path = $this->path($key);
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            clearstatcache(true, $path);
            $this->assertDirectoryReadable();

            if (!file_exists($path)) {
                return null;
            }

            throw new CacheFailed('Cannot read the cache file.');
        }

        try {
            $raw = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if ($raw === false) {
            throw new CacheFailed('Cannot read the cache file.');
        }

        try {
            /** @var mixed $entry */
            $entry = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new CacheFailed('The cache file is corrupt.', 0, $error);
        }

        if (!is_array($entry) || !isset($entry['expires'], $entry['value'])
            || !is_int($entry['expires']) || !is_string($entry['value'])) {
            throw new CacheFailed('The cache file has an invalid format.');
        }

        // Do not unlink on a read: another process may have replaced this entry.
        return $entry['expires'] !== 0 && $entry['expires'] <= time() ? null : $entry['value'];
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        Cache::validateTtl($ttlSeconds);
        $path = $this->path($key);
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $payload = json_encode(
            ['expires' => $ttlSeconds === 0 ? 0 : time() + $ttlSeconds, 'value' => $value],
            JSON_THROW_ON_ERROR,
        );

        try {
            $handle = @fopen($temporary, 'xb');

            if ($handle === false) {
                throw new CacheFailed('Cannot write the cache file.');
            }

            try {
                // Make the empty file private before writing any cached data.
                if (!@chmod($temporary, 0o600)
                    || @fwrite($handle, $payload) !== strlen($payload)
                    || !@fflush($handle)) {
                    throw new CacheFailed('Cannot write the cache file.');
                }
            } finally {
                fclose($handle);
            }

            if (!@rename($temporary, $path)) {
                throw new CacheFailed('Cannot commit the cache file.');
            }
        } finally {
            if (is_file($temporary) && !@unlink($temporary)) {
                throw new CacheFailed('Cannot remove the temporary cache file.');
            }
        }
    }

    public function delete(string $key): void
    {
        $this->remove($this->path($key));
    }

    public function clear(): void
    {
        try {
            $files = new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException $error) {
            throw new CacheFailed('Cannot list the cache directory.', 0, $error);
        }

        foreach ($files as $path => $file) {
            if (preg_match('/^' . $this->prefix . '[a-f0-9]{64}\.cache$/D', basename($path)) === 1) {
                $this->remove($path);
            }
        }
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . $this->prefix . hash('sha256', $key) . '.cache';
    }

    private function remove(string $path): void
    {
        if (!@unlink($path)) {
            clearstatcache(true, $path);
            $this->assertDirectoryReadable();

            if (file_exists($path)) {
                throw new CacheFailed('Cannot delete the cache file.');
            }
        }

    }

    private function assertDirectoryReadable(): void
    {
        if (!is_dir($this->directory) || !is_readable($this->directory)) {
            throw new CacheFailed('The file cache directory is unavailable.');
        }
    }
}
