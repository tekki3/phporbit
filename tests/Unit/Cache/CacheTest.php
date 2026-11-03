<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Cache;

use InvalidArgumentException;
use PhpOrbit\Cache\Cache;
use PhpOrbit\Cache\CacheFactory;
use PhpOrbit\Cache\CacheFailed;
use PhpOrbit\Cache\FileCacheStore;
use PhpOrbit\Config\Environment;
use PhpOrbit\Config\MissingConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class CacheTest extends TestCase
{
    private string $directory;
    private Cache $cache;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/orbit-cache-' . bin2hex(random_bytes(6));
        $this->cache = new Cache(new FileCacheStore($this->directory));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    /** @param scalar|array<array-key, mixed>|null $value */
    #[DataProvider('values')]
    public function test_json_values_round_trip_without_changing_their_types(string|int|float|bool|array|null $value): void
    {
        $this->cache->set('value', $value);
        self::assertTrue($this->cache->has('value'));
        self::assertSame($value, $this->cache->get('value'));
        self::assertSame($value, $this->cache->remember('value', static fn (): string => 'not used'));
    }

    /** @return iterable<string, array{scalar|array<array-key, mixed>|null}> */
    public static function values(): iterable
    {
        yield 'null is a hit' => [null];
        yield 'false is a hit' => [false];
        yield 'true' => [true];
        yield 'zero' => [0];
        yield 'float' => [1.0];
        yield 'empty string' => [''];
        yield 'unicode' => ["caf\u{00e9}"];
        yield 'empty array' => [[]];
        yield 'nested arrays' => [['rows' => [['id' => 1, 'ok' => true]], 'other' => null]];
        yield 'numeric keys' => [[2 => 'two', 5 => [null, 1.5]]];
    }

    public function test_misses_delete_and_repeated_delete(): void
    {
        self::assertNull($this->cache->get('missing'));
        self::assertFalse($this->cache->has('missing'));
        $this->cache->set('key', 'value');
        $this->cache->delete('key');
        $this->cache->delete('key');
        self::assertFalse($this->cache->has('key'));
    }

    public function test_remember_runs_once_even_when_the_callback_returns_null(): void
    {
        $calls = 0;
        $compute = static function () use (&$calls): null {
            $calls++;

            return null;
        };

        self::assertNull($this->cache->remember('nullable', $compute));
        self::assertNull($this->cache->remember('nullable', $compute));
        self::assertSame(1, $calls);
    }

    public function test_callback_failure_is_not_cached_or_swallowed(): void
    {
        try {
            $this->cache->remember('failure', static fn (): never => throw new RuntimeException('calculation failed'));
            self::fail('Expected the callback exception.');
        } catch (RuntimeException $error) {
            self::assertSame('calculation failed', $error->getMessage());
        }

        self::assertFalse($this->cache->has('failure'));
    }

    public function test_expiration_is_enforced_at_the_exact_boundary_and_zero_means_forever(): void
    {
        $before = time();
        $this->cache->set('expired', 'value', 1);
        $file = $this->onlyCacheFile();
        /** @var array{expires: int, value: string} $entry */
        $entry = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual($before + 1, $entry['expires']);
        $entry['expires'] = time();
        file_put_contents($file, json_encode($entry, JSON_THROW_ON_ERROR));
        self::assertFalse($this->cache->has('expired'));
        self::assertSame('fresh', $this->cache->remember('expired', static fn (): string => 'fresh'));

        $this->cache->set('forever', false, 0);
        self::assertFalse($this->cache->get('forever'));
        self::assertTrue($this->cache->has('forever'));
    }

    public function test_clear_only_removes_the_current_namespace_and_not_templates_or_other_files(): void
    {
        $other = new Cache(new FileCacheStore($this->directory, 'other-app'));
        $other->set('key', 'other');
        $this->cache->set('key', 'own');
        file_put_contents($this->directory . '/template.php', 'untouched');
        $this->cache->clear();

        self::assertFalse($this->cache->has('key'));
        self::assertSame('other', $other->get('key'));
        self::assertFileExists($this->directory . '/template.php');
    }

    public function test_independent_instances_see_changes_without_local_memoization(): void
    {
        $second = new Cache(new FileCacheStore($this->directory));
        $this->cache->set('key', 'first');
        self::assertSame('first', $second->get('key'));
        $second->set('key', 'second');
        self::assertSame('second', $this->cache->get('key'));
        $second->clear();
        self::assertFalse($this->cache->has('key'));
    }

    public function test_keys_cannot_escape_the_cache_directory_and_files_are_private(): void
    {
        $this->cache->set('../outside/a key with spaces' . "\0", 'safe');
        $file = $this->onlyCacheFile();
        self::assertSame($this->directory, dirname($file));
        self::assertSame(0o600, fileperms($file) & 0o777);
        self::assertSame(0o700, fileperms($this->directory) & 0o777);
        self::assertSame([], glob($this->directory . '/*.tmp'));
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_keys_are_refused(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->get($key);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 1025)];
    }

    public function test_invalid_ttl_is_refused_even_on_a_remember_hit(): void
    {
        $this->cache->set('key', 'hit');
        $this->expectException(InvalidArgumentException::class);
        $this->cache->remember('key', static fn (): string => 'miss', -1);
    }

    public function test_objects_nested_in_arrays_are_refused_without_calling_serialization_hooks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->set('object', ['nested' => [new stdClass()]]);
    }

    public function test_resources_nested_in_arrays_are_refused(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->cache->set('resource', [$resource]);
        } finally {
            fclose($resource);
        }
    }

    public function test_recursive_arrays_are_refused(): void
    {
        $value = [];
        $value['self'] = &$value;
        $this->expectException(InvalidArgumentException::class);
        $this->cache->set('recursive', $value);
    }

    public function test_non_json_numbers_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->set('number', INF);
    }

    public function test_invalid_utf8_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->set('string', "\xff");
    }

    public function test_corrupt_storage_is_an_explicit_failure_not_a_miss(): void
    {
        $this->cache->set('key', 'value');
        file_put_contents($this->onlyCacheFile(), '{broken');
        $this->expectException(CacheFailed::class);
        $this->cache->remember('key', static fn (): string => 'must not run');
    }

    public function test_corrupt_encoded_values_are_an_explicit_failure(): void
    {
        $store = new FileCacheStore($this->directory);
        $store->set(hash('sha256', 'key'), 'not-json', 0);
        $this->expectException(CacheFailed::class);
        $this->cache->get('key');
    }

    public function test_storage_errors_are_not_hidden(): void
    {
        // A regular file in place of a directory fails even when run as root.
        file_put_contents($this->directory . '/not-a-directory', '');
        $this->expectException(CacheFailed::class);
        new FileCacheStore($this->directory . '/not-a-directory');
    }

    public function test_a_directory_removed_after_boot_is_not_reported_as_a_cache_miss(): void
    {
        rmdir($this->directory);

        try {
            $this->expectException(CacheFailed::class);
            $this->cache->get('key');
        } finally {
            mkdir($this->directory, 0o700);
        }
    }

    public function test_factory_defaults_to_file_and_resolves_custom_paths_against_root(): void
    {
        $default = CacheFactory::fromEnvironment(Environment::fromArray([]), $this->directory);
        $default->set('key', 'default');
        self::assertSame('default', $default->get('key'));
        $default->clear();
        rmdir($this->directory . '/storage/cache/data');
        rmdir($this->directory . '/storage/cache');
        rmdir($this->directory . '/storage');

        $custom = CacheFactory::fromEnvironment(Environment::fromArray([
            'CACHE_DRIVER' => 'storage',
            'CACHE_PATH' => '.',
            'CACHE_PREFIX' => 'custom',
            'CACHE_TTL' => '45',
        ]), $this->directory);
        $custom->set('key', 'custom');
        self::assertSame('custom', $custom->get('key'));
        self::assertFalse($this->cache->has('key'));
    }

    public function test_unknown_drivers_are_configuration_errors(): void
    {
        $this->expectException(MissingConfiguration::class);
        CacheFactory::fromEnvironment(Environment::fromArray(['CACHE_DRIVER' => 'typo']), $this->directory);
    }

    public function test_blank_prefix_is_a_configuration_error(): void
    {
        $this->expectException(MissingConfiguration::class);
        $this->expectExceptionMessage('CACHE_PREFIX');
        CacheFactory::fromEnvironment(Environment::fromArray(['CACHE_PREFIX' => '']), $this->directory);
    }

    public function test_negative_default_ttl_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CacheFactory::fromEnvironment(Environment::fromArray(['CACHE_TTL' => '-1']), $this->directory);
    }

    public function test_cache_paths_with_glob_metacharacters_work_and_clear_normally(): void
    {
        $path = $this->directory . '/[cache]';
        $cache = new Cache(new FileCacheStore($path));
        $cache->set('key', 'value');
        self::assertSame('value', $cache->get('key'));
        $cache->clear();
        self::assertFalse($cache->has('key'));
        rmdir($path);
    }

    #[DataProvider('remoteDrivers')]
    public function test_missing_extensions_are_reported_clearly(string $driver): void
    {
        if (extension_loaded($driver)) {
            self::markTestSkipped('This test exercises an absent extension.');
        }

        $this->expectException(CacheFailed::class);
        $this->expectExceptionMessage('ext-' . $driver);
        CacheFactory::fromEnvironment(Environment::fromArray(['CACHE_DRIVER' => $driver]), $this->directory);
    }

    /** @return iterable<string, array{string}> */
    public static function remoteDrivers(): iterable
    {
        yield 'redis' => ['redis'];
        yield 'memcached' => ['memcached'];
    }

    private function onlyCacheFile(): string
    {
        $files = glob($this->directory . '/*.cache') ?: [];
        self::assertCount(1, $files);

        return $files[0];
    }
}
