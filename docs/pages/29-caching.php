<?php

declare(strict_types=1);

return [
    'slug' => 'caching',
    'title' => 'Caching',
    'summary' => 'One injectable cache API for file storage, Redis and Memcached.',
    'body' => <<<'HTML'
<p>Inject <code>PhpOrbit\Cache\Cache</code> into a controller or service. The demo and blank project bootstraps already register it. The default stores data in <code>storage/cache/data</code>, outside the document root; no server or extra PHP extension is needed.</p>

<h2>Start with remember()</h2>

<p><code>remember()</code> returns the cached value, or runs your callback and stores its result on a miss. Its TTL is in seconds.</p>

[[php]]
<?php
namespace App\Controllers;

use PhpOrbit\Cache\Cache;
use PhpOrbit\Database\Connection;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class TotalsController implements Handler
{
    public function __construct(
        private readonly Cache $cache,
        private readonly Connection $database,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $count = $this->cache->remember(
            'notes:count',
            fn (): int => $this->database->query('notes')->count(),
            ttlSeconds: 60,
        );

        return Response::json(['count' => $count]);
    }
}
[[/php]]

<p>This example caches a shared total. For private data, include the authenticated user or tenant id in the key and check authorization before reading the cache. A cache entry is shared deliberately, across requests and users; it is not request-local storage.</p>

<h2>The API</h2>

[[php]]
<?php
$cache->set('price:42', ['amount' => 19.95, 'currency' => 'EUR'], 300);
$price = $cache->get('price:42');      // The array above, or null on a miss.
$exists = $cache->has('price:42');     // true, even for cached null or false.
$cache->delete('price:42');           // Deleting an absent entry is safe.

$cache->set('feature:enabled', false); // Uses CACHE_TTL (300 seconds by default).
$cache->set('reference:version', 7, 0); // 0 means no expiration.
$cache->set('nullable', null);
$cache->remember('nullable', fn (): string => 'not called');

$cache->clear();                      // Only this application's namespace.
[[/php]]

<ul>
<li>Values may be strings, integers, finite floats, booleans, null, or nested arrays of those values. Empty arrays and scalar types survive a round trip.</li>
<li>Objects (including models and <code>JsonSerializable</code>), resources, recursive arrays, invalid UTF-8 and non-finite numbers are refused. Convert a model into an array of data first. Nothing uses PHP <code>serialize()</code> or <code>unserialize()</code>.</li>
<li><code>get()</code> returns null for a miss <em>and</em> a cached null. <code>has()</code> distinguishes them; <code>remember()</code> treats null as a hit. It does not confuse false, zero or an empty string with a miss.</li>
<li>Keys contain 1 to 1024 bytes and are hashed before reaching a backend, so punctuation cannot create a path or a Memcached protocol command.</li>
<li>Omitting the TTL uses <code>CACHE_TTL</code>; zero never expires. Negative TTLs and expiration beyond the portable signed Unix timestamp limit (2038) are refused. Expiration is checked on reads, not dependent on a scheduler.</li>
</ul>

<p>The returned value is a JSON-data union, not an inferred model or array shape. Narrow with <code>is_array()</code>, <code>is_string()</code>, etc. before using it as a particular type. Nested array elements are a JSON boundary, validated recursively on writes.</p>

<h2>Choose a backend</h2>

<p>Change the environment and restart long-lived workers. The API and controller code stay the same.</p>

<h3>File storage (default)</h3>

[[ini]]
CACHE_DRIVER=file
CACHE_TTL=300
CACHE_PATH=storage/cache/data
[[/ini]]

<p><code>CACHE_DRIVER=storage</code> is an alias for <code>file</code>. Relative paths resolve against the project root, not the current working directory. Files are written atomically with mode 0600; newly created directories use 0700. Use a private, trusted directory outside <code>public/</code>, dedicated to caching. File cache entries are local to that filesystem, not shared across separate servers. Expired entries are misses; their files remain until overwritten or cleared, avoiding deletion races with concurrent writers.</p>

<h3>Redis</h3>

[[ini]]
CACHE_DRIVER=redis
CACHE_HOST=127.0.0.1
CACHE_PORT=6379
CACHE_DATABASE=0
CACHE_TIMEOUT=2
CACHE_PREFIX=my-app-production
# CACHE_USERNAME=app
# CACHE_PASSWORD=...
[[/ini]]

<p>Install <code>ext-redis</code> and run a Redis server. <code>CACHE_USERNAME</code> is optional for ACL authentication; a password without a username uses Redis password authentication. <code>CACHE_DATABASE</code> selects a non-negative database index. A username without a password is an error. For a TLS-enabled Redis endpoint use <code>CACHE_HOST=tls://cache.example.test</code>; PHP's TLS verification remains enabled.</p>

<h3>Memcached</h3>

[[ini]]
CACHE_DRIVER=memcached
CACHE_HOST=127.0.0.1
CACHE_PORT=11211
CACHE_TIMEOUT=2
CACHE_PREFIX=my-app-production
[[/ini]]

<p>Install <code>ext-memcached</code> and run a Memcached server. The adapter handles Memcached's special interpretation of TTLs longer than 30 days. This adapter does not implement SASL or TLS: use a local server or a trusted private network, never a public endpoint.</p>

<h3>Namespaces and clearing</h3>

<p><code>CACHE_PREFIX</code> defaults to a hash of the project root, isolating local projects automatically. Set an explicit, unique prefix for each application and environment in production. All instances sharing a cache must use the same prefix; an application whose deployment path changes should also set a stable prefix. Do not share a prefix between applications or environments.</p>

[[bash]]
./orbit cache:clear
[[/bash]]

<p>This reads the cache configuration without booting the application or connecting to its database. It clears data only for the configured backend and namespace. <code>./orbit storage:clear</code> still clears compiled templates only; it does not touch application data. The admin Storage page likewise manages templates, not this cache.</p>

<p>Redis clearing scans and deletes namespaced keys rather than using <code>FLUSHDB</code>. Memcached has no safe namespaced enumeration, so clearing replaces a shared random generation token instead of using a server-wide flush. Existing values from the old generation become unreachable and remain on the server until expiration or eviction (including indefinitely until eviction for TTL 0). Generation tokens are read from the server on each operation, so other workers see a clear immediately. Token eviction also causes safe misses, never resurrection of a previous generation.</p>

<h2>Failures and concurrency</h2>

<p>Backend connection failures, failed writes and corrupt cache data throw <code>PhpOrbit\Cache\CacheFailed</code>. Invalid keys, values or TTLs throw <code>InvalidArgumentException</code>; invalid environment settings name the configuration key. There is no silent fallback to another driver and no success-shaped response when a backend is unavailable. Catch <code>CacheFailed</code> at an application boundary if your application deliberately chooses to log the failure and run without caching.</p>

<p><code>remember()</code> is cache-aside, not a distributed lock: simultaneous misses may run the callback more than once. Callback exceptions propagate and are not cached. Concurrent writes are last-write-wins; clearing is not a transaction with concurrent writers. Do not rely on caches for uniqueness, authorization, a durable queue, or correctness-critical locking. Entries can be evicted early by the server.</p>

<p>The cache service retains no decoded values, callbacks or request objects in memory. Every read reaches the store, so external updates and clears are visible in long-lived workers. File writes use rename, letting readers see a complete old or new value. The same API works under both worker and per-request SAPIs.</p>

<h2>Existing applications and tests</h2>

<p>Already-created projects can add the binding below inside their boot callback. New blank and demo projects include it automatically. Configuration and remote connections are resolved lazily on the first use of <code>Cache</code>.</p>

[[php]]
<?php
use PhpOrbit\Cache\Cache;
use PhpOrbit\Cache\CacheFactory;

$app->container->singleton(
    Cache::class,
    static fn (): Cache => CacheFactory::fromEnvironment($env, $root),
);
[[/php]]

<p>For offline tests, construct <code>new Cache(new FileCacheStore($temporaryDirectory, 'test'))</code> and clear/remove the temporary directory in teardown. Live integration tests use <code>REDIS_HOST</code>/<code>REDIS_PORT</code> and <code>MEMCACHED_HOST</code>/<code>MEMCACHED_PORT</code>, with their corresponding PHP extensions. Redis authentication can be supplied through <code>REDIS_USERNAME</code>/<code>REDIS_PASSWORD</code>.</p>
HTML,
];
