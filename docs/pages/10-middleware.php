<?php

declare(strict_types=1);

return [
    'slug' => 'middleware',
    'title' => 'Middleware',
    'summary' => 'Wrapping requests: ordering, short-circuiting, per-route layers, and the ones that ship with the framework.',
    'body' => <<<'HTML'
<p>Middleware wraps request handling. Each layer receives the request, the request scope, and a <code>$next</code> to call — or not.</p>

[[php]]
<?php
namespace App\Middleware;

use Closure;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Middleware\Middleware;

final class AddRequestId implements Middleware
{
    public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
    {
        $id = bin2hex(random_bytes(8));

        // Everything further in sees the modified request.
        $response = $next($request->withAttribute('requestId', $id));

        // On the way back out, the response can be modified too.
        return $response->withHeader('X-Request-Id', $id);
    }
}
[[/php]]

<div class="warn">
<b>Middleware objects are shared</b>
<p>They are constructed at boot and reused for every request the worker serves, so they must hold no mutable state of their own. Anything request-specific comes from the <code>RequestScope</code> passed in.</p>
</div>

<h2>Registering</h2>

[[php]]
<?php
Application::boot(static function (Blueprint $app) use ($root): void {
    // Global: outermost first.
    $app->middleware(
        new LogRequests($logger),
        new ServeStaticFiles($root . '/public', maxAgeSeconds: 3600),
        new SessionMiddleware($sessions, lifetimeSeconds: 7200),
        new CsrfMiddleware(),
        new TransactionGuard(),
    );

    $app->loadRoutes($root . '/app/routes.php');
});
[[/php]]

<p><code>orbit make:middleware Name</code> writes the class above and prints the <code>new Name(),</code> entry to place in that list — where it goes is left to you, since order here is meaning rather than plumbing. See <a href="cli.html">The orbit CLI</a>.</p>

[[php]]
<?php
// Per route
$routes->get('/reports', ReportController::class, 'reports', middleware: [
    new RequireAuthentication(),
]);

// Across a set of routes
$routes->withMiddleware([new RequireAuthentication()], static function (RouteCollection $routes): void {
    $routes->post('/articles', StoreController::class, 'articles.store');
});
[[/php]]

<h2>Order</h2>

<p>Registration order, outermost first. Route middleware runs inside the global stack.</p>

[[text]]
LogRequests            ── sees everything, including 404s and failures
  ServeStaticFiles     ── may return a file and stop here
    SessionMiddleware  ── loads the session, publishes it
      CsrfMiddleware   ── needs the session that the layer above published
        TransactionGuard
          RequireAuthentication      (route middleware)
            your handler
[[/text]]

<div class="note">
<b>Order is not arbitrary</b>
<p><code>CsrfMiddleware</code> must come after <code>SessionMiddleware</code>, because the token lives in the session. <code>LogRequests</code> goes first so it observes the true status of everything, including responses produced by layers below it.</p>
</div>

<h2>Short-circuiting</h2>

<p>A layer that does not call <code>$next</code> stops the request:</p>

[[php]]
<?php
final class BlockDuringMaintenance implements Middleware
{
    public function __construct(private readonly bool $enabled)
    {
    }

    public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
    {
        if ($this->enabled && !str_starts_with($request->uri->path, '/health')) {
            return Response::text('Back shortly.', Status::ServiceUnavailable)
                ->withHeader('Retry-After', '120');
        }

        return $next($request);
    }
}
[[/php]]

<h2>Seeing the matched route</h2>

<p>Routing happens <em>before</em> the pipeline, so a layer can inspect the route it is wrapping — while still running for requests that matched nothing:</p>

[[php]]
<?php
use PhpOrbit\Routing\Route;

public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
{
    // No route on a 404, so ask before taking it.
    $name = $scope->provided(Route::class) ? $scope->get(Route::class)->name : null;

    return $next($request);
}
[[/php]]

<p>This is exactly how <code>CsrfMiddleware</code> honours <code>csrfExempt: true</code> on a single route.</p>

<h2>Cleanup that always runs</h2>

[[php]]
<?php
public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
{
    $span = $this->tracer->start($request->uri->path);

    try {
        return $next($request);
    } finally {
        // Runs on the error path too, which is when traces matter most.
        $span->finish();
    }
}
[[/php]]

<h2>What ships with the framework</h2>

<div class="scroller">
<table>
<thead><tr><th>Middleware</th><th>Purpose</th></tr></thead>
<tbody>
<tr><td><code>LogRequests</code></td><td>One structured line per request: method, path, status, duration.</td></tr>
<tr><td><code>ServeStaticFiles</code></td><td>Serves files from a root, with ETag and 304 handling.</td></tr>
<tr><td><code>SessionMiddleware</code></td><td>Loads the session, publishes it, writes it back, sets the cookie.</td></tr>
<tr><td><code>CsrfMiddleware</code></td><td>Rejects state-changing requests without a valid token.</td></tr>
<tr><td><code>TransactionGuard</code></td><td>Rolls back any transaction a handler left open.</td></tr>
<tr><td><code>RequireAuthentication</code></td><td>Redirects guests to the sign-in page, remembering where they were going.</td></tr>
</tbody>
</table>
</div>

<h3>ServeStaticFiles</h3>

[[php]]
<?php
// Mounted at the root
new ServeStaticFiles($root . '/public', maxAgeSeconds: 3600);

// Mounted under a prefix — this is how /docs is served from docs/
// without copying generated files into the document root.
new ServeStaticFiles($root . '/docs', maxAgeSeconds: 3600, prefix: '/docs');
[[/php]]

<p>Path safety rests on two independent checks: <code>Uri</code> has already resolved dot segments and rejected encoded separators, and this resolves the candidate with <code>realpath()</code> and confirms the result is still inside the root — which also catches a symlink pointing out of it. Dotfiles are never served, so <code>.env</code> and <code>.git</code> are unreachable even if they end up under a root.</p>

<p>A prefix matches only on a segment boundary, so a <code>/docs</code> mount answers <code>/docs</code> and <code>/docs/…</code> but never <code>/docsomething</code>. Requesting a directory serves its <code>index.html</code>.</p>

<div class="warn">
<b>Only listed types are served</b>
<p>The media-type table is an allowlist, and a file whose extension is not on it falls through to the router. That is not fussiness: the earlier behaviour — unknown types sent as <code>application/octet-stream</code> — meant this middleware would hand out the <em>source</em> of any file under its root, <code>public/index.php</code> included. A server whose job is to return files verbatim must never be pointed at code, and refusing everything it was not told about is the only reliable way to guarantee that.</p>
<p>Add an entry to <code>MIME_TYPES</code> when you need a type that is missing. The failure mode is a 404 — discoverable, and safe.</p>
</div>

<p>Behind nginx or Apache this is effectively dead code: those serve real files before PHP is invoked, which is faster and why the front controllers test for a file first.</p>

<h3>TransactionGuard</h3>

<p>The connection is a process-lifetime singleton, so a handler that opens a transaction and throws would hand the next request a connection already inside someone else's transaction. Under FPM the process dies and the driver rolls back; under a worker nothing does. The guard closes that, and reports it rather than staying silent:</p>

[[text]]
Transaction left open by POST /articles was rolled back. A handler opened a
transaction without committing it.
[[/text]]

<h2>Testing a middleware</h2>

[[php]]
<?php
public function test_it_adds_a_request_id(): void
{
    $scope = (new Container())->enterRequest();

    $response = (new AddRequestId())->process(
        Requests::get('/'),
        $scope,
        static fn (ServerRequest $r): Response => Response::text($r->attribute('requestId') ?? ''),
    );

    self::assertNotSame('', $response->body);
    self::assertSame($response->body, $response->headers->first('X-Request-Id'));
}
[[/php]]
HTML,
];
