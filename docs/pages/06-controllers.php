<?php

declare(strict_types=1);

return [
    'slug' => 'controllers',
    'title' => 'Controllers',
    'summary' => 'Single-action classes, constructor injection, and when a closure is the better answer.',
    'body' => <<<'HTML'
<p>A controller is a class implementing <code>Handler</code>. One class, one route.</p>

[[php]]
<?php
namespace App\Controllers;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class ShowArticleController implements Handler
{
    public function handle(ServerRequest $request): Response
    {
        return Response::text('Article ' . $request->attribute('id'));
    }
}
[[/php]]

[[php]]
<?php
$routes->get('/articles/{id:\d+}', ShowArticleController::class, 'articles.show');
[[/php]]

<div class="note">
<b>Why one action per class</b>
<p>A <code>[Controller::class, 'method']</code> pair can only be invoked dynamically, and a dynamic call returns <code>mixed</code>. That defeats the type guarantees the rest of the framework depends on. An interface keeps the signature statically checkable, and PHPStan can see that <code>handle()</code> returns a <code>Response</code>.</p>
<p>In practice a class per action also stops the 800-line controller that accumulates seven unrelated responsibilities.</p>
</div>

<h2>Dependencies</h2>

<p>Constructor parameters are resolved from the request scope. Nothing to register:</p>

[[php]]
<?php
final class ListArticlesController implements Handler
{
    public function __construct(
        private readonly Connection $database,      // boot singleton
        private readonly TemplateEngine $view,      // boot singleton
        private readonly Session $session,          // published by middleware
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $articles = $this->database->query('articles')
            ->orderBy('created_at', Direction::Descending)
            ->limit(20)
            ->get();

        return $this->view->respond('articles/index', [
            'title' => 'Articles',
            'articles' => $articles,
            'notice' => $this->session->takeFlash('notice'),
        ]);
    }
}
[[/php]]

<p>Resolution order for each parameter:</p>

<ol>
<li>Already provided for this request (the session, the matched route, the request scope)?</li>
<li>Registered as <code>scoped()</code>? Build it, once per request.</li>
<li>Registered as <code>singleton()</code>? Use the process-wide instance.</li>
<li>Otherwise autowire it, per request, from its own constructor.</li>
</ol>

<p>The controller itself is autowired, so it is built fresh per request and may hold state safely for that request's duration.</p>

<h3>What cannot be autowired</h3>

[[php]]
<?php
final class ReportController implements Handler
{
    public function __construct(
        private readonly Connection $database,
        private readonly int $pageSize,        // no class type, no default
    ) {
    }
}

// CannotAutowire: Cannot resolve "$pageSize" of App\Controllers\ReportController::__construct():
//   it has no class type and no default. Register "App\Controllers\ReportController"
//   with an explicit factory.
[[/php]]

<p>Give it a default, or register the class explicitly:</p>

[[php]]
<?php
$app->container->scoped(
    ReportController::class,
    static fn (RequestScope $scope): ReportController => new ReportController(
        $scope->get(Connection::class),
        pageSize: 50,
    ),
);
[[/php]]

<p>Guessing a scalar would silently inject a value nobody chose, so it is refused instead.</p>

<h2>Closures</h2>

<p>Closures receive the request and the scope, and are the right tool when a file would be ceremony:</p>

[[php]]
<?php
$routes->get('/health', static fn (): Response => Response::json(['status' => 'ok']));

$routes->get('/whoami', static function (ServerRequest $request, RequestScope $scope): Response {
    $session = $scope->get(Session::class);

    return Response::text($session->get('name') ?? 'guest');
});
[[/php]]

<div class="good">
<b>Rule of thumb</b>
<p>Closure if it fits on one line and needs nothing injected. A class the moment it needs a dependency, a template, or a test of its own.</p>
</div>

<h2>Returning things</h2>

[[php]]
<?php
// Rendered HTML
return $this->view->respond('articles/show', ['article' => $article]);

// JSON
return Response::json(['id' => 42, 'title' => $title]);

// Redirect after a successful write, so a refresh does not repost
return Response::redirect('/articles');

// Nothing to say
return Response::noContent();

// A specific status
return Response::text('Not your article.', Status::Forbidden);
[[/php]]

<p><a href="responses.html">Full response API &rarr;</a></p>

<h2>A complete example</h2>

<p>Create an article: validate, write, flash, redirect.</p>

[[php]]
<?php
namespace App\Controllers;

use PhpOrbit\Database\Connection;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;
use PhpOrbit\Validation\Validator;
use PhpOrbit\View\TemplateEngine;

final class CreateArticleController implements Handler
{
    public function __construct(
        private readonly Connection $database,
        private readonly Session $session,
        private readonly TemplateEngine $view,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $validator = Validator::forRequest($request)
            ->required('title')
            ->maxLength('title', 120)
            ->required('body')
            ->maxLength('body', 5000);

        if ($validator->fails()) {
            // Re-render with the errors rather than redirecting, so the user
            // keeps what they typed.
            return $this->view->respond('articles/new', [
                'title' => 'New article',
                'errors' => $validator->errors(),
                'old' => $request->formData(),
            ], Status::UnprocessableEntity);
        }

        $this->database->query('articles')->insert([
            'title' => $validator->validated('title'),
            'body' => $validator->validated('body'),
            'created_at' => gmdate('c'),
        ]);

        $this->session->flash('notice', 'Article published.');

        return Response::redirect('/articles');
    }
}
[[/php]]

<p>CSRF is already enforced for this <code>POST</code> — nothing in the controller asks for it. <a href="security.html">Why &rarr;</a></p>
HTML,
];
