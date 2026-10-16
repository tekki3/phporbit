<?php

declare(strict_types=1);

return [
    'slug' => 'index',
    'title' => 'phporbit',
    'nav' => 'Overview',
    'summary' => 'A safe PHP framework that runs on itself. One application, unchanged, on its own server, FrankenPHP, nginx+FPM or Apache.',
    'body' => <<<'HTML'
<p>phporbit is a small PHP framework built around one constraint: <strong>the same application runs unchanged on four deployment targets</strong>, and it never has to know which one it is on.</p>

<div class="good">
<b>New to phporbit? Start here.</b>
<p>A framework connects URLs to your PHP code and gives you tools for pages, forms and databases. Follow <a href="getting-started.html">Getting started</a> to install phporbit, run a blank application and build a page that greets a visitor by name. It explains the terms, includes Windows instructions and shows what you should see after each step. Basic PHP and HTML are enough to begin; the architecture chapters can wait.</p>
</div>

<div class="scroller">
<table>
<thead><tr><th>Target</th><th>Process model</th><th>Used for</th></tr></thead>
<tbody>
<tr><td><code>./orbit serve</code></td><td>Long-lived, self-hosted</td><td>Development</td></tr>
<tr><td>FrankenPHP worker</td><td>Long-lived</td><td>Production</td></tr>
<tr><td>nginx + PHP-FPM</td><td>Per-request</td><td>Production</td></tr>
<tr><td>Apache</td><td>Per-request</td><td>Production</td></tr>
</tbody>
</table>
</div>

<p>&ldquo;Runs on itself&rdquo; is literal. <code>./orbit serve</code> is a real HTTP/1.1 server built on sockets, sharing the exact request pipeline used in production — not a router script in front of <code>php -S</code>.</p>

<h2>How a page fits together</h2>

<p>In a generated project, these three files illustrate a complete page. A <strong>route</strong> selects a <strong>controller</strong>, and the controller renders a <strong>template</strong>. For step-by-step instructions that preserve your starter routes, follow the <a href="getting-started.html#first-controller">first-page tutorial</a>.</p>

[[php]]
<?php
declare(strict_types=1);

// app/routes.php
use App\Controllers\HelloController;
use PhpOrbit\Http\Response;
use PhpOrbit\Routing\RouteCollection;

return static function (RouteCollection $routes, bool $debug): void {
    $routes->get('/', static fn (): Response => Response::text('Hello.'));

    $routes->get('/hello/{name}', HelloController::class, 'hello');
};
[[/php]]

[[php]]
<?php
declare(strict_types=1);

// app/src/Controllers/HelloController.php
namespace App\Controllers;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\View\TemplateEngine;

final class HelloController implements Handler
{
    public function __construct(private readonly TemplateEngine $view)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        // {{ }} escapes. The payload arrives as text, not markup.
        return $this->view->respond('hello', [
            'title' => 'Hello',
            'name' => $request->attribute('name'),
        ]);
    }
}
[[/php]]

<p>Save the template as <code>app/templates/hello.orbit.php</code>. It uses the generated project's existing layout:</p>

[[html]]
@extends('layout')

@section('content')
<h1>Hello, {{ $name }}!</h1>
@endsection
[[/html]]

<p><code>{{ $name }}</code> prints the value supplied by the controller with HTML escaping. The <code>title</code> value supplies the browser tab's title in the layout.</p>

[[bash]]
php orbit serve --debug
[[/bash]]

<p>Open <code>http://127.0.0.1:8080/hello/Ada</code> to see <strong>Hello, Ada!</strong>. If the server is already running, stop it with Ctrl+C before starting it again to pick up PHP changes. Template-only edits appear on refresh in debug mode.</p>

<h2>What it gives you</h2>

<div class="cards">
    <a href="routing.html"><b>Routing</b><span>Compiled at boot, named routes, URL generation</span></a>
    <a href="container.html"><b>Container</b><span>Singletons, request scopes, autowiring</span></a>
    <a href="middleware.html"><b>Middleware</b><span>Global and per-route, ordered, short-circuiting</span></a>
    <a href="templates.html"><b>Templates</b><span>Auto-escaping, layouts, sections, compiled once</span></a>
    <a href="database.html"><b>Database</b><span>Prepared statements only, query builder, migrations</span></a>
    <a href="auth.html"><b>Authentication</b><span>Argon2id, session fixation defence, throttling</span></a>
    <a href="sessions.html"><b>Sessions</b><span>Own implementation — not PHP's process-global one</span></a>
    <a href="uploads.html"><b>Uploads</b><span>Quotas, content sniffing, guaranteed cleanup</span></a>
</div>

<h2>Two ideas worth knowing before you start</h2>

<h3>1. Worker safety is a correctness rule, not a deployment concern</h3>

<p>The four targets split into two incompatible process models:</p>

<ul>
<li><strong>Per-request</strong> (Apache, nginx+FPM) tears down the process after every response. Global state is free — nothing survives to leak.</li>
<li><strong>Long-lived workers</strong> (the built-in server, FrankenPHP) boot once and serve thousands of requests in one process. Anything mutable that outlives a request leaks <em>across users</em>.</li>
</ul>

<p>Code that is safe under a worker is automatically correct per-request. The reverse is not true. So phporbit assumes the worker model everywhere, and the framework is shaped to make the leak impossible rather than to warn you about it. <a href="architecture.html">How that is enforced &rarr;</a></p>

<h3>2. The safe path is the default path</h3>

<p>You do not opt into safety; you opt out of it, visibly:</p>

<ul>
<li>Templates escape with <code>{{ }}</code>. Raw output needs the deliberately loud <code>{!! !!}</code>.</li>
<li>CSRF protection is on. A route opts out with <code>csrfExempt: true</code>.</li>
<li>The query builder binds values as parameters. When writing SQL yourself, use placeholders rather than interpolating user input.</li>
<li>An <code>UPDATE</code> or <code>DELETE</code> with no <code>where()</code> throws unless you call <code>affectingEveryRow()</code>.</li>
<li>Uploads are judged by their bytes, never by their filename or declared type.</li>
</ul>

<div class="note">
<b>Where to go next</b>
<p>Start with <a href="getting-started.html">Getting started</a>, then explore <a href="routing.html">routing</a>, <a href="templates.html">templates</a> and <a href="forms.html">forms</a>. When you are ready to understand service lifetimes and worker safety, read <a href="architecture.html">Architecture</a>.</p>
</div>
HTML,
];
