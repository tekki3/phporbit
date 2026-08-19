<?php

declare(strict_types=1);

return [
    'slug' => 'testing',
    'title' => 'Testing',
    'summary' => 'Unit tests, worker-mode tests that catch state leaks, integration tests against real servers, and the static scans that catch what tests cannot.',
    'body' => <<<'HTML'
[[bash]]
$ composer test                              # everything
$ vendor/bin/phpunit --testsuite unit        # unit only
$ vendor/bin/phpunit --testsuite worker      # process-model tests only
$ vendor/bin/phpunit --testsuite integration # needs real servers; skips without them
$ vendor/bin/phpunit --filter test_name      # one test
$ vendor/bin/phpunit tests/Unit/Http/UriTest.php
$ composer stan                              # PHPStan, max level
[[/bash]]

<h2>Four kinds of test</h2>

<div class="scroller">
<table>
<thead><tr><th>Directory</th><th>Catches</th></tr></thead>
<tbody>
<tr><td><code>tests/Unit/</code></td><td>Ordinary component behaviour.</td></tr>
<tr><td><code>tests/Worker/</code></td><td>State leaking between requests in one process.</td></tr>
<tr><td><code>tests/Integration/</code></td><td>SQL or SMTP a real server rejects.</td></tr>
<tr><td><code>tests/Unit/PortabilityTest.php</code></td><td>Code that only works on one SAPI.</td></tr>
</tbody>
</table>
</div>

<h2>Testing a handler</h2>

<p>Boot an application and hand it a request. No HTTP server involved:</p>

[[php]]
<?php
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Tests\Support\Requests;

public function test_it_greets_by_name(): void
{
    $app = Application::boot(static function (Blueprint $app): void {
        $app->routes->get('/greet/{name}', static fn (ServerRequest $r): Response =>
            Response::text('hello ' . $r->attribute('name')));
    });

    $response = $app->handle(Requests::get('/greet/ada'));

    self::assertSame(Status::Ok, $response->status);
    self::assertSame('hello ada', $response->body);
}
[[/php]]

<h3>The request helper</h3>

[[php]]
<?php
Requests::get('/articles?page=2');
Requests::post('/articles', 'title=Hello');
Requests::of(Method::Delete, '/articles/1', headers: ['X-Trace' => 'abc']);
[[/php]]

<h2>Worker-mode tests</h2>

<div class="note">
<b>Why they exist</b>
<p>State-leak bugs are <strong>invisible</strong> under per-request execution. Apache and FPM destroy the process between requests, so a leak has nothing to leak into. Under a worker the same bug serves one user's data to the next. Every test in <code>tests/Worker/</code> therefore boots once and handles at least twice.</p>
</div>

<p>The canonical shape — serve the same route twice and assert the second is unaffected by the first:</p>

[[php]]
<?php
public function test_a_scoped_service_starts_fresh_for_each_request(): void
{
    $app = Application::boot(static function (Blueprint $app): void {
        $app->container->scoped(Counter::class, static fn (): Counter => new Counter());

        $app->routes->get('/count', static fn (ServerRequest $r, RequestScope $scope): Response =>
            Response::text((string) $scope->get(Counter::class)->increment()));
    });

    self::assertSame('1', $app->handle(Requests::get('/count'))->body);
    self::assertSame('1', $app->handle(Requests::get('/count'))->body);
    self::assertSame('1', $app->handle(Requests::get('/count'))->body);
}
[[/php]]

<p>Pair it with the opposite case, or the test can pass by being vacuous:</p>

[[php]]
<?php
public function test_a_singleton_persists_across_requests(): void
{
    // ... registered with singleton() instead ...

    self::assertSame('1', $app->handle(Requests::get('/count'))->body);
    self::assertSame('2', $app->handle(Requests::get('/count'))->body);
}
[[/php]]

<h3>Teardown on the error path</h3>

[[php]]
<?php
public function test_teardown_runs_even_when_the_handler_throws(): void
{
    $released = 0;

    $app = Application::boot(static function (Blueprint $app) use (&$released): void {
        $app->routes->get('/boom', static function (ServerRequest $r, RequestScope $scope) use (&$released): Response {
            $scope->onClose(static function () use (&$released): void {
                $released++;
            });

            throw new RuntimeException('handler failed');
        });
    });

    self::assertSame(Status::InternalServerError, $app->handle(Requests::get('/boom'))->status);
    self::assertSame(Status::InternalServerError, $app->handle(Requests::get('/boom'))->status);
    self::assertSame(2, $released);
}
[[/php]]

<h3>Memory growth</h3>

[[php]]
<?php
public function test_memory_does_not_grow_across_many_requests(): void
{
    // Warm up first, so first-call allocations are not counted as growth.
    for ($i = 0; $i < 200; $i++) {
        $app->handle(Requests::get('/count/' . $i));
    }

    gc_collect_cycles();
    $baseline = memory_get_usage();

    for ($i = 0; $i < 2000; $i++) {
        $app->handle(Requests::get('/count/' . $i));
    }

    gc_collect_cycles();

    self::assertLessThan(256 * 1024, memory_get_usage() - $baseline);
}
[[/php]]

<p>A slow leak is invisible across a handful of requests and fatal across a worker's lifetime.</p>

<h2>Testing over real HTTP</h2>

<p><code>OrbitServerTest</code> starts <code>./orbit serve</code> as a subprocess and drives it over TCP, which is what substantiates &ldquo;runs on itself&rdquo; — the pipeline is answered over a socket, not called in-process.</p>

[[php]]
<?php
public function test_it_serves_the_index(): void
{
    $response = $this->request("GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

    self::assertStringStartsWith('HTTP/1.1 200 OK', $response);
    self::assertStringContainsString('X-Content-Type-Options: nosniff', $response);
}
[[/php]]

<h2>Testing the database</h2>

<p>SQLite in memory is fast enough to give every test a fresh schema:</p>

[[php]]
<?php
protected function setUp(): void
{
    $this->database = new Connection(new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]));

    (new Migrator($this->database, __DIR__ . '/../../database/migrations'))->migrate();
}
[[/php]]

<p>Running the real migrations means the tests also prove the migrations work.</p>

<h2>Integration tests</h2>

<p>SQLite answers the question &ldquo;does this code run?&rdquo; It cannot answer &ldquo;does MySQL accept this?&rdquo; — and that is a different question, because a double quote is a string literal on MySQL, a bare <code>OFFSET</code> is a syntax error on two of the three engines, and <code>lastInsertId()</code> means something else again on PostgreSQL. The unit tests assert the SQL phporbit <em>generates</em> by inspecting <code>toSql()</code>. The integration tests assert a server <em>accepts</em> it.</p>

<p>The same applies to mail: the unit tests drive the SMTP conversation over a socket pair with scripted replies, which proves phporbit says the right things — not that a server understands them.</p>

[[bash]]
$ MYSQL_HOST=127.0.0.1 MYSQL_PORT=3306 MYSQL_DATABASE=orbit_test \
  MYSQL_USERNAME=orbit MYSQL_PASSWORD=orbit \
  vendor/bin/phpunit --testsuite integration
[[/bash]]

<div class="note">
<b>They skip, they do not fail</b>
<p>Every test in this suite asks for its server first and calls <code>markTestSkipped()</code> when the environment variables are unset or nothing is listening. That keeps <code>composer test</code> useful on a laptop with only SQLite installed. The cost is that a green run proves nothing on its own — which is why CI passes <code>--fail-on-skipped</code>, turning a missing service into a failure there.</p>
</div>

<p>Writing one is a matter of using the trait and naming what you need:</p>

[[php]]
<?php
final class MyIntegrationTest extends TestCase
{
    use RequiresService;

    protected function setUp(): void
    {
        $env = $this->requireEnvironment(['MYSQL_HOST', 'MYSQL_PORT'], 'MySQL');

        $this->requireReachable($env['MYSQL_HOST'], (int) $env['MYSQL_PORT'], 'MySQL');
    }
}
[[/php]]

<h2>Continuous integration</h2>

<p><code>.github/workflows/ci.yml</code> runs the things a laptop cannot check. Each job exists because something is otherwise only asserted:</p>

<div class="scroller">
<table>
<thead><tr><th>Job</th><th>What it establishes</th></tr></thead>
<tbody>
<tr><td>Tests</td><td>Both gates on PHP 8.3, 8.4 and 8.5. The floor is where new syntax breaks silently.</td></tr>
<tr><td>Integration</td><td>MySQL, PostgreSQL and Mailpit as service containers, with <code>--fail-on-skipped</code>.</td></tr>
<tr><td>Per-request SAPI</td><td>Boots the demo through a web SAPI and fetches real pages. The suite runs under the CLI, so this is the only place a CLI-only construct actually surfaces.</td></tr>
<tr><td>Long-lived worker</td><td>The other process model, on its own.</td></tr>
<tr><td>Documentation</td><td>Rebuilds <code>docs/</code> and fails if the committed pages differ.</td></tr>
</tbody>
</table>
</div>

<p>The per-request job asks for <code>/</code> and fails if the response carries <code>check-fail</code> — the class a failing self-check row wears. It also checks the page rendered at all first, because an empty response contains no failures either.</p>

<h2>The static scans</h2>

<p>Some bugs cannot be caught by running code, because the suite runs under one SAPI. <code>PortabilityTest</code> reads the source instead and fails on:</p>

<ul>
<li><code>STDERR</code>, <code>STDOUT</code>, <code>STDIN</code> outside the CLI-only entrypoints.</li>
<li>Superglobals outside the SAPI adapters.</li>
<li>Any use of PHP's session extension.</li>
</ul>

<p>It tokenises rather than pattern-matches, so the prose explaining why a construct is banned does not itself trip the check — and it carries a test proving the scanner reads code rather than comments, so it cannot pass by being blind.</p>

<div class="good">
<b>This exists because of a real bug</b>
<p>An earlier version used <code>STDERR</code> in <code>app/bootstrap.php</code>. Every CLI path worked, the whole suite passed, and the application fatal-errored the first time a web server booted it. A unit test could never have caught it.</p>
</div>

<h2>Static analysis</h2>

[[bash]]
$ composer stan
[[/bash]]

<p>PHPStan at max level, over <code>src</code>, <code>tests</code>, <code>app</code>, <code>public</code>, <code>database</code> and <code>orbit</code>. <strong>No baseline and no <code>ignoreErrors</code></strong> — a finding is a defect to fix, not a warning to record. A baseline turns &ldquo;we have no type errors&rdquo; into &ldquo;we have a list of type errors we have agreed not to look at&rdquo;.</p>

<h2>What to test when extending the framework</h2>

<ul>
<li><strong>Both process models.</strong> If it holds state, add a worker test that handles at least twice.</li>
<li><strong>The error path.</strong> Resources must be released when a handler throws, not only when it returns.</li>
<li><strong>The refusal.</strong> Assert that invalid input is rejected, not merely that valid input works — most safety properties are about what does <em>not</em> happen.</li>
</ul>
HTML,
];
