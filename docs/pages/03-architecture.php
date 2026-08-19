<?php

declare(strict_types=1);

return [
    'slug' => 'architecture',
    'title' => 'Architecture',
    'summary' => 'The two process models, the boot/request split that follows from them, and the request lifecycle.',
    'body' => <<<'HTML'
<p>Almost every design decision in phporbit follows from one fact. It is worth ten minutes.</p>

<h2>Two incompatible process models</h2>

<p>The four deployment targets are really two:</p>

<ul>
<li><strong>Per-request.</strong> Apache and nginx+FPM start a process, serve one request, and tear it down. Global state is free — a leak has nothing to leak into, because nothing survives.</li>
<li><strong>Long-lived worker.</strong> The built-in server and FrankenPHP boot once and serve thousands of requests in the same process. Anything mutable that outlives a request is visible to <em>the next user</em>.</li>
</ul>

<p>A cached authenticated user, a request-scoped binding left in the container, a static property, an open transaction — under FPM these are invisible. Under a worker they are a security bug.</p>

<div class="note">
<b>The rule</b>
<p>Worker-safe code is automatically correct on per-request SAPIs. The reverse is false. So <strong>assume the worker model everywhere</strong>, and treat worker safety as a correctness invariant rather than a deployment concern.</p>
</div>

<h2>The boot / request split</h2>

<p>phporbit separates the two phases by construction, not by convention.</p>

[[php]]
<?php
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;

$app = Application::boot(static function (Blueprint $app): void {
    // BOOT PHASE — runs once per process.
    // Register services, middleware and routes here.
    $app->container->singleton(Clock::class, static fn (): Clock => new SystemClock());
    $app->loadRoutes(__DIR__ . '/routes.php');
});

// The moment boot() returns:
//   * the route table is compiled
//   * the container is frozen
//   * every property of $app is readonly
//
// There is nothing mutable left to accumulate into.

$response = $app->handle($request);   // REQUEST PHASE — runs many times
[[/php]]

<p>Try to register a service after boot and you get an exception, not a subtle bug:</p>

[[php]]
<?php
$app->container()->singleton(Clock::class, $factory);
// PhpOrbit\Container\Exception\ContainerFrozen:
//   Cannot register "Clock": the container was frozen when the application
//   finished booting. Registering during a request would leak the definition
//   into every later request served by this worker process.
[[/php]]

<h2>How each leak is closed</h2>

<div class="scroller">
<table>
<thead><tr><th>Leak</th><th>What stops it</th></tr></thead>
<tbody>
<tr><td>Service registered mid-request</td><td><code>Container::freeze()</code> throws after boot</td></tr>
<tr><td>Per-request object cached process-wide</td><td>Autowiring lives only on <code>RequestScope</code>, never on <code>Container</code></td></tr>
<tr><td>Scope held past its request</td><td><code>RequestScope::close()</code> makes later use throw <code>ScopeClosed</code></td></tr>
<tr><td>Resource never released</td><td>The scope closes in a <code>finally</code>, so it runs on the error path too</td></tr>
<tr><td>Transaction left open on a shared connection</td><td><code>TransactionGuard</code> rolls it back and logs</td></tr>
<tr><td>Upload temp files piling up</td><td>The kernel discards them when the scope closes</td></tr>
<tr><td>One user's session reaching another</td><td>Sessions are per-request objects, not <code>$_SESSION</code></td></tr>
<tr><td>Render state shared between pages</td><td><code>TemplateEngine</code> is config only; a <code>Renderer</code> is created per render</td></tr>
</tbody>
</table>
</div>

<h2>Three service lifetimes</h2>

[[php]]
<?php
// Once per process. Shared by every request. Must be stateless.
$app->container->singleton(Connection::class, static fn (): Connection => $database);

// Once per request. Disposed when the request ends.
$app->container->scoped(Cart::class, static fn (RequestScope $scope): Cart => new Cart());

// Not registered at all: autowired per request from the constructor signature.
final class ReportController implements Handler
{
    public function __construct(
        private readonly Connection $database,   // the singleton
        private readonly Session $session,       // published by middleware
    ) {
    }
}
[[/php]]

<div class="warn">
<b>Why autowiring is scope-only</b>
<p>If the container cached an autowired instance, that instance would be shared by every later request in a worker. So <code>Container::get()</code> still refuses anything unregistered; only <code>RequestScope::get()</code> will build it, and what it builds dies with the request.</p>
</div>

<h2>The request lifecycle</h2>

[[text]]
enterRequest()                        a fresh RequestScope is opened
  │
  ├─ provide(RequestScope)            so handlers can inject the scope
  ├─ schedule upload cleanup          runs even if something throws
  │
  ├─ router->match(request)           routing happens BEFORE middleware
  │    └─ provide(Route)              so middleware can see which route matched
  │
  ├─ global middleware  ─────────┐    outermost first
  │    route middleware ─────┐   │
  │      handler            ─┘   │
  │    ◄──────────────────────────    response travels back out
  │
  └─ finally: scope->close()          teardown, always
[[/text]]

<p>Routing before the pipeline is a deliberate choice. It means a middleware can read the matched route — that is how CSRF honours a route's exemption — while still running for requests that matched nothing, so logging and auditing see 404s.</p>

<h2>Where environment-specific code is allowed</h2>

<p>Only in <code>src/Sapi/</code> and the entrypoints (<code>orbit</code>, <code>public/index.php</code>). Above that boundary the request object is identical on all four targets.</p>

<p>That includes CLI-only constants:</p>

[[php]]
<?php
// WRONG — STDERR is defined only under the CLI SAPI. This is a fatal error at
// boot under FPM, Apache and php -S.
$logger = new StreamLogger(STDERR);

// RIGHT — the php://stderr wrapper exists on every SAPI, and lands in the
// terminal under CLI, the pool log under FPM, the server log under Apache.
$logger = StreamLogger::standardError();
[[/php]]

<div class="note">
<b>This is enforced, not just documented</b>
<p><code>tests/Unit/PortabilityTest.php</code> tokenises the whole source tree and fails the build on CLI-only constants, superglobals outside the SAPI boundary, and any use of PHP's session extension. It tokenises rather than pattern-matches so the prose explaining a rule does not itself trip it. The test suite runs under the CLI, so nothing else would catch this class of bug.</p>
</div>

<h2>Testing the invariant</h2>

<p>State-leak bugs are invisible under per-request execution, so they get their own suite. Every test in <code>tests/Worker/</code> boots once and handles at least twice:</p>

[[php]]
<?php
public function test_a_scoped_service_starts_fresh_for_each_request(): void
{
    $app = Application::boot(static function (Blueprint $app): void {
        $app->container->scoped(Counter::class, static fn (): Counter => new Counter());

        $app->routes->get('/count', static fn (ServerRequest $r, RequestScope $scope): Response =>
            Response::text((string) $scope->get(Counter::class)->increment()));
    });

    // If the scope leaked, the second call would return "2".
    self::assertSame('1', $app->handle(Requests::get('/count'))->body);
    self::assertSame('1', $app->handle(Requests::get('/count'))->body);
}
[[/php]]

<p>When you add framework-level behaviour, test it under both process models. <a href="testing.html">More on testing &rarr;</a></p>
HTML,
];
