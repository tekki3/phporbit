<?php

declare(strict_types=1);

return [
    'slug' => 'getting-started',
    'title' => 'Getting started',
    'summary' => 'Install, migrate, seed, serve — then add your first route, controller and template.',
    'body' => <<<'HTML'
<h2>Requirements</h2>

<ul>
<li><strong>PHP 8.3 or newer</strong>, with <code>pdo_sqlite</code>, <code>mbstring</code> and <code>fileinfo</code>.</li>
<li><strong>Composer</strong>.</li>
<li><code>sockets</code> and <code>pcntl</code> for the built-in server (present in most CLI builds).</li>
</ul>

<h2>Starting a project</h2>

[[bash]]
$ orbit new my-app          # a blank application
$ orbit new my-app --demo   # or the demo, with auth, uploads and the self-check

$ cd my-app
$ composer install
$ ./orbit migrate
$ ./orbit serve
[[/bash]]

<p>Open <code>http://127.0.0.1:8080</code>. <a href="cli.html">More on <code>orbit new</code> &rarr;</a></p>

<h2>Running this repository</h2>

<p>If you cloned phporbit itself rather than scaffolding from it:</p>

[[bash]]
$ composer install
$ cp .env.example .env
$ ./orbit migrate
$ ./orbit db:seed
$ ./orbit serve
[[/bash]]

<p>Open <code>http://127.0.0.1:8080</code>. You get a self-check page that exercises routing, sessions, CSRF, migrations, the query builder, escaping and worker isolation, and reports pass or fail for each.</p>

<div class="note">
<b>What those commands did</b>
<p><code>migrate</code> created the schema and recorded it in a ledger table. <code>db:seed</code> created a demo account (<code>demo@example.test</code> / <code>correct-horse-battery</code>). <code>serve</code> started phporbit's own HTTP server, which applies any pending migrations first as a development convenience.</p>
</div>

<h2>Your first route</h2>

<p>Routes live in <code>app/routes.php</code>. Add one:</p>

[[php]]
<?php
use PhpOrbit\Http\Response;
use PhpOrbit\Routing\RouteCollection;

return static function (RouteCollection $routes, bool $debug): void {
    // ... existing routes ...

    $routes->get('/ping', static fn (): Response => Response::json(['pong' => true]), 'ping');
};
[[/php]]

<p>Reload. No build step, no cache to clear — the server re-reads the application on each request in development.</p>

[[bash]]
$ curl http://127.0.0.1:8080/ping
{"pong":true}
[[/bash]]

<h2>Your first controller</h2>

<p>A closure is fine for one-liners. Anything with dependencies belongs in a class implementing <code>Handler</code>:</p>

[[php]]
<?php
// app/src/Controllers/GreetController.php
namespace App\Controllers;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\View\TemplateEngine;

final class GreetController implements Handler
{
    // Constructor dependencies are resolved automatically, per request.
    public function __construct(
        private readonly TemplateEngine $view,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->view->respond('greet', [
            'title' => 'Greetings',
            'name' => $request->attribute('name') ?? 'world',
        ]);
    }
}
[[/php]]

[[php]]
<?php
$routes->get('/greet/{name}', GreetController::class, 'greet');
[[/php]]

<h2>Your first template</h2>

<p>Templates are <code>app/templates/*.orbit.php</code>. <code>{{ }}</code> escapes; nothing else needed:</p>

[[html]]
{# app/templates/greet.orbit.php #}
@extends('layout')

@section('content')
    <h1>Hello, {{ $name }}</h1>

    <p>Try /greet/&lt;script&gt;alert(1)&lt;/script&gt; and view the source.</p>
@endsection
[[/html]]

<p>The payload arrives as text, not markup, because escaping is what <code>{{ }}</code> does — not something this template remembered to ask for.</p>

<h2>Storing something</h2>

<p>Write a migration, run it, then use the query builder:</p>

[[php]]
<?php
// database/migrations/0004_create_pings.php
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migration;

return new class implements Migration {
    public function up(Connection $database): void
    {
        $database->executeSchema(
            'CREATE TABLE pings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                note TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
        );
    }

    public function down(Connection $database): void
    {
        $database->executeSchema('DROP TABLE pings');
    }
};
[[/php]]

[[bash]]
$ ./orbit migrate
Applied 0004_create_pings
[[/bash]]

[[php]]
<?php
use PhpOrbit\Database\Connection;

$id = $database->query('pings')->insert([
    'note' => 'first',
    'created_at' => gmdate('c'),
]);

$recent = $database->query('pings')
    ->where('note', '!=', '')
    ->orderBy('id', Direction::Descending)
    ->limit(10)
    ->get();
[[/php]]

<h2>Project layout</h2>

<div class="scroller">
<table>
<thead><tr><th>Path</th><th>What lives there</th></tr></thead>
<tbody>
<tr><td><code>app/routes.php</code></td><td>Route declarations</td></tr>
<tr><td><code>app/bootstrap.php</code></td><td>Boot phase: services, middleware, configuration</td></tr>
<tr><td><code>app/src/</code></td><td>Your classes (<code>App\</code> namespace)</td></tr>
<tr><td><code>app/templates/</code></td><td><code>*.orbit.php</code> templates</td></tr>
<tr><td><code>database/migrations/</code></td><td>Schema changes</td></tr>
<tr><td><code>public/</code></td><td>Document root: front controller, assets</td></tr>
<tr><td><code>storage/</code></td><td>Sessions, compiled templates, SQLite file (git-ignored)</td></tr>
<tr><td><code>src/</code></td><td>The framework itself</td></tr>
</tbody>
</table>
</div>

<h2>Useful commands</h2>

[[bash]]
$ ./orbit serve --port=9000 --debug   # --debug shows exceptions, recompiles templates
$ ./orbit routes                      # print the compiled route table
$ ./orbit migrate:status              # what has run, and what has not
$ composer test                       # the test suite
$ composer stan                       # static analysis at max level
[[/bash]]

<div class="warn">
<b>One thing to avoid</b>
<p>Do not use <code>php -S</code> as your development server. It works, and it is a quick way to exercise the per-request path, but it is not one of the four supported targets and it does not share the pipeline that production uses. <code>./orbit serve</code> runs the same code as FrankenPHP, which is what makes a bug reproducible on your machine.</p>
</div>
HTML,
];
