<?php

declare(strict_types=1);

return [
    'slug' => 'routing',
    'title' => 'Routing',
    'summary' => 'Declaring routes, capturing parameters, grouping, naming, and generating URLs.',
    'body' => <<<'HTML'
<p>Routes live in <code>app/routes.php</code>, which returns a closure. The file is loaded during the boot phase, so routes still land before the table is compiled — living in their own file changes <em>where</em> they are written, not <em>when</em> they take effect.</p>

[[php]]
<?php
// app/routes.php
use App\Controllers\HomeController;
use PhpOrbit\Routing\RouteCollection;

return static function (RouteCollection $routes, bool $debug): void {
    $routes->get('/', HomeController::class, 'home');
};
[[/php]]

[[php]]
<?php
// app/bootstrap.php — inside the boot callback
$app->loadRoutes($root . '/app/routes.php');
[[/php]]

<h2>Methods</h2>

[[php]]
<?php
$routes->get('/articles', IndexController::class, 'articles.index');
$routes->post('/articles', StoreController::class, 'articles.store');
$routes->put('/articles/{id}', ReplaceController::class, 'articles.replace');
$routes->patch('/articles/{id}', UpdateController::class, 'articles.update');
$routes->delete('/articles/{id}', DeleteController::class, 'articles.delete');
[[/php]]

<p><code>HEAD</code> is served automatically by the matching <code>GET</code> route; the kernel strips the body and leaves the headers identical. For anything else, use <code>add()</code>:</p>

[[php]]
<?php
use PhpOrbit\Http\Method;

$routes->add(Method::Post, '/webhooks/stripe', StripeWebhook::class, 'webhooks.stripe', csrfExempt: true);
[[/php]]

<h2>Handlers</h2>

<p>A route points at either a controller class or a closure.</p>

[[php]]
<?php
// A class implementing Handler. Constructor dependencies are autowired.
$routes->get('/articles', IndexController::class, 'articles.index');

// A closure, for things too small to deserve a file.
$routes->get('/ping', static fn (): Response => Response::json(['pong' => true]));

// A closure receives the request and the request scope.
$routes->get('/whoami', static fn (ServerRequest $request, RequestScope $scope): Response =>
    Response::text($scope->get(Session::class)->get('name') ?? 'guest'));
[[/php]]

<p><a href="controllers.html">More on controllers &rarr;</a></p>

<h2>Parameters</h2>

[[php]]
<?php
$routes->get('/users/{id}', ShowUser::class, 'users.show');
$routes->get('/users/{id}/posts/{slug}', ShowPost::class, 'posts.show');
[[/php]]

<p>Captured values arrive as request attributes:</p>

[[php]]
<?php
public function handle(ServerRequest $request): Response
{
    $id = $request->attribute('id');        // string|null
    $slug = $request->attribute('slug');

    return Response::text("{$id} / {$slug}");
}
[[/php]]

<div class="note">
<b>A parameter never spans path segments</b>
<p>An unconstrained <code>{name}</code> matches <code>[^/]+</code>. <code>/files/{name}</code> will not match <code>/files/a/b</code>. Placeholders that quietly swallow slashes are a common source of routes matching far more than their author intended.</p>
</div>

<h3>Constraints</h3>

[[php]]
<?php
$routes->get('/orders/{id:\d+}', ShowOrder::class, 'orders.show');
$routes->get('/posts/{slug:[a-z0-9-]+}', ShowPost::class, 'posts.show');
$routes->get('/reports/{year:\d{4}}/{month:\d{2}}', Report::class, 'reports.show');
[[/php]]

<p>The constraint is a regular expression spliced into the compiled pattern. A broken one fails at <strong>boot</strong>, not on the first request that happens to reach the route:</p>

[[text]]
PhpOrbit\Routing\Exception\InvalidRoutePattern:
  Route pattern "/orders/{id:[0-9}" compiles to an invalid regex; check its constraints.
[[/text]]

<h2>Grouping</h2>

<h3>By path prefix</h3>

[[php]]
<?php
$routes->group('/api/v1', static function (RouteCollection $routes): void {
    $routes->get('/users', ApiUsers::class, 'api.users');       // /api/v1/users
    $routes->get('/orders', ApiOrders::class, 'api.orders');    // /api/v1/orders
});
[[/php]]

<p>Groups nest, and the prefix is restored in a <code>finally</code> — a throwing callback cannot leak its prefix onto routes declared afterwards.</p>

<h3>By middleware</h3>

[[php]]
<?php
use PhpOrbit\Auth\RequireAuthentication;

$routes->withMiddleware([new RequireAuthentication()], static function (RouteCollection $routes): void {
    $routes->post('/articles', StoreController::class, 'articles.store');
    $routes->post('/articles/{id:\d+}/delete', DeleteController::class, 'articles.delete');
});
[[/php]]

<div class="good">
<b>State a guard once</b>
<p><code>withMiddleware()</code> is a prefix-less <code>group()</code> with its own name, because &ldquo;these require a signed-in user&rdquo; and &ldquo;these live under /admin&rdquo; are different statements. Prefer it to repeating the guard per line — a guard repeated on every line is one that eventually gets left off one of them.</p>
</div>

<p>Both can be combined:</p>

[[php]]
<?php
$routes->group('/admin', static function (RouteCollection $routes): void {
    $routes->get('/', Dashboard::class, 'admin.home');
    $routes->get('/users', AdminUsers::class, 'admin.users');
}, [new RequireAuthentication('/login')]);
[[/php]]

<h2>Per-route middleware</h2>

[[php]]
<?php
$routes->get('/reports', ReportController::class, 'reports', middleware: [
    new RequireAuthentication(),
    new RateLimit(perMinute: 10),
]);
[[/php]]

<p>Route middleware runs <em>inside</em> the global stack, after everything registered with <code>$app->middleware(...)</code>.</p>

<h2>Names and URL generation</h2>

<p>Naming a route means a path can change in one place.</p>

[[php]]
<?php
$router = $app->router();

$router->urlFor('home');                              // "/"
$router->urlFor('users.show', ['id' => 42]);          // "/users/42"
$router->urlFor('posts.show', ['slug' => 'a b/c']);   // "/posts/a%20b%2Fc"
$router->hasName('users.show');                       // true
[[/php]]

<p>Generation is strict, so a broken link surfaces where it is built rather than as a 404 for whoever clicks it:</p>

[[php]]
<?php
$router->urlFor('users.show');
// UnknownRoute: Route "users.show" needs a value for {id}.

$router->urlFor('orders.show', ['id' => 'abc']);   // route is {id:\d+}
// UnknownRoute: The values given for route "orders.show" do not satisfy its pattern "/orders/{id:\d+}".

$router->urlFor('typo');
// UnknownRoute: No route is named "typo". Known names: home, users.show, posts.show.
[[/php]]

<p>Duplicate names are rejected at boot too.</p>

<h2>How matching works</h2>

<ul>
<li><strong>Static routes</strong> go into a hash keyed by method and path, so the common case costs one array lookup no matter how many routes exist.</li>
<li><strong>Parameterised routes</strong> are scanned in registration order.</li>
<li><strong>Trailing slashes</strong> are collapsed: <code>/docs</code> and <code>/docs/</code> address the same route.</li>
<li><strong>No match</strong> gives 404; <strong>wrong method</strong> gives 405 with an <code>Allow</code> header listing what is accepted.</li>
</ul>

[[bash]]
$ curl -i -X POST http://127.0.0.1:8080/articles/1
HTTP/1.1 405 Method Not Allowed
Allow: GET, DELETE
[[/bash]]

<h2>Debug-only routes</h2>

<p>The closure receives the debug flag, so a route can exist only while debugging without the file reading the environment itself:</p>

[[php]]
<?php
if ($debug) {
    $routes->get('/__routes', static fn (): Response => Response::text('...'), 'debug.routes');
}
[[/php]]

<h2>Inspecting the table</h2>

[[bash]]
$ ./orbit routes
GET     /                                        self-check
GET     /avatar                                  avatar
POST    /avatar                                  avatar.store
GET     /health                                  health
GET     /hello/{name}                            hello
GET     /login                                   login
POST    /login                                   login.attempt
POST    /logout                                  logout
GET     /notes                                   notes.index
POST    /notes                                   notes.create
POST    /notes/{id:\d+}/delete                   notes.delete
[[/bash]]
HTML,
];
