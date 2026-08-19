<?php

declare(strict_types=1);

return [
    'slug' => 'container',
    'title' => 'Container',
    'summary' => 'Three service lifetimes, autowiring, publishing per-request values, and deterministic teardown.',
    'body' => <<<'HTML'
<p>There are two objects. <code>Container</code> holds process-lifetime definitions and is frozen when boot ends. <code>RequestScope</code> holds everything belonging to one request and is thrown away when it finishes.</p>

<h2>Registering services</h2>

[[php]]
<?php
Application::boot(static function (Blueprint $app): void {
    // Once per process, shared by every request.
    $app->container->singleton(
        Connection::class,
        static fn (): Connection => Connection::sqlite($path),
    );

    // Once per request.
    $app->container->scoped(
        ShoppingCart::class,
        static fn (RequestScope $scope): ShoppingCart => new ShoppingCart(
            $scope->get(Session::class),
        ),
    );
});
[[/php]]

<div class="warn">
<b>A singleton must be stateless</b>
<p>It is shared by every request the worker ever serves. Configuration, connections and compiled tables are fine. Anything that remembers <em>this</em> request — the current user, a cart, a request id — must be <code>scoped()</code> or autowired, or it becomes one user's data shown to the next.</p>
</div>

<p><code>orbit make:class Name --singleton</code> or <code>--scoped</code> writes the class with that constraint as its comment and prints the registration line above, so the choice is made while the file is being created rather than after a leak.</p>

<h3>Scoped factories receive the scope</h3>

<p>Note the parameter type: <code>scoped()</code> hands your factory the <code>RequestScope</code>, not the <code>Container</code>. That is deliberate — resolving a dependency through the scope keeps you in the same request. Handing it the container would tempt a factory into opening a second scope and quietly missing everything middleware published into the real one.</p>

<h2>Autowiring</h2>

<p>An unregistered class is built from its constructor signature:</p>

[[php]]
<?php
final class ArticleRepository
{
    public function __construct(private readonly Connection $database)
    {
    }
}

final class ShowArticle implements Handler
{
    // Nothing registered. Both are built per request.
    public function __construct(private readonly ArticleRepository $articles)
    {
    }
}
[[/php]]

<div class="note">
<b>Autowiring is scope-only, on purpose</b>
<p><code>Container::get()</code> refuses anything unregistered. Only <code>RequestScope::get()</code> will autowire, and what it builds dies with the request. If the container cached an autowired instance, that instance would be shared by every later request in the worker — the exact leak the two-phase split exists to prevent.</p>
</div>

<p>Failures are explicit, and name the parameter that blocked resolution:</p>

[[text]]
CannotAutowire: Cannot resolve "$timeout" of App\Mailer::__construct(): it has no
  class type and no default. Register "App\Mailer" with an explicit factory.

CannotAutowire: Cannot resolve "App\Clock": it is an interface or abstract class,
  so it must be registered with an explicit factory.

CannotAutowire: Circular dependency while resolving "App\A": App\A -> App\B -> App\A
[[/text]]

<h2>Interfaces</h2>

<p>Bind the interface, depend on the interface:</p>

[[php]]
<?php
$app->container->singleton(
    Clock::class,
    static fn (): Clock => new SystemClock(),
);

final class ExpiryCheck
{
    public function __construct(private readonly Clock $clock)
    {
    }
}
[[/php]]

<p>Swapping in a <code>FrozenClock</code> for tests is then a one-line change at boot.</p>

<h2>Publishing per-request values</h2>

<p>Middleware uses <code>provide()</code> to hand something to everything further in. This is how the session and the matched route reach your controller:</p>

[[php]]
<?php
public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
{
    $tenant = $this->tenants->forHost($request->uri->host);

    $scope->provide(Tenant::class, $tenant);

    return $next($request);
}
[[/php]]

[[php]]
<?php
// Anywhere downstream, including autowired constructors
final class Dashboard implements Handler
{
    public function __construct(private readonly Tenant $tenant)
    {
    }
}
[[/php]]

<p><code>provided()</code> asks whether an optional collaborator exists, which is how a layer stays useful when something upstream did not run:</p>

[[php]]
<?php
if (!$scope->provided(Session::class)) {
    return Response::text('SessionMiddleware must run first.', Status::InternalServerError);
}
[[/php]]

<p>What the framework publishes for you:</p>

<div class="scroller">
<table>
<thead><tr><th>Type</th><th>Published by</th><th>Available when</th></tr></thead>
<tbody>
<tr><td><code>RequestScope</code></td><td>Kernel</td><td>Always</td></tr>
<tr><td><code>Route</code></td><td>Kernel</td><td>A route matched</td></tr>
<tr><td><code>ServerRequest</code></td><td>Kernel</td><td>At handler dispatch</td></tr>
<tr><td><code>Session</code></td><td><code>SessionMiddleware</code></td><td>That middleware is registered</td></tr>
<tr><td><code>Authenticator</code></td><td>Registered as <code>scoped()</code> in bootstrap</td><td>Always</td></tr>
</tbody>
</table>
</div>

<h2>Teardown</h2>

<p><code>onClose()</code> registers work to run when the request ends — including when the handler throws:</p>

[[php]]
<?php
$scope->onClose(static function () use ($handle): void {
    fclose($handle);
});
[[/php]]

<p>Callbacks run in reverse order, and <em>every</em> one runs even if an earlier throws, so a single failing release cannot strand the rest. The scope then marks itself closed:</p>

[[php]]
<?php
$stale = $scope;
// ... request ends ...
$stale->get(Session::class);
// ScopeClosed: This request scope has been closed. Holding a reference to it
//   beyond the request it belongs to would expose one request's state to another.
[[/php]]

<p>An exception is far kinder than silently serving the previous visitor's session.</p>

<h2>Resolution order</h2>

<p>For <code>$scope->get(Foo::class)</code>:</p>

<ol>
<li>Already built or provided this request? Return it.</li>
<li>A <code>scoped()</code> definition? Build it, cache for this request.</li>
<li>A <code>singleton()</code> definition? Delegate to the container.</li>
<li>Otherwise autowire it, per request.</li>
</ol>

<h2>The freeze</h2>

[[php]]
<?php
$app->container()->isFrozen();   // true, once boot() has returned

$app->container()->singleton(Foo::class, $factory);
// ContainerFrozen: Cannot register "Foo": the container was frozen when the
//   application finished booting. Registering during a request would leak the
//   definition into every later request served by this worker process.
[[/php]]

<p>This is the structural half of worker safety: not a convention to remember, but an exception you cannot get past.</p>
HTML,
];
