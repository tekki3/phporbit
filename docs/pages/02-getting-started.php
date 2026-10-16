<?php

declare(strict_types=1);

return [
    'slug' => 'getting-started',
    'title' => 'Getting started',
    'summary' => 'Create a project, run it locally, and build your first page with a route, controller and template.',
    'body' => <<<'HTML'
<p>This guide takes you from an empty folder to a page that says <strong>Hello, Ada!</strong>. Start with a blank project and follow the numbered steps in order. You do not need a separate web server or database server.</p>

<h2 id="before-you-start">1. Check your tools</h2>

<p>You should be comfortable creating files, opening a terminal and writing basic PHP (variables, arrays and functions), plus a little HTML. We explain the framework-specific terms as they appear.</p>

<ul>
<li><strong>PHP 8.3 or newer</strong>, with <code>pdo_sqlite</code>, <code>mbstring</code>, <code>fileinfo</code> and <code>sodium</code>. SQLite stores your database in a file; no database server is needed for this guide.</li>
<li><strong><a href="https://getcomposer.org/">Composer</a></strong>, PHP's package manager. It downloads phporbit and the libraries your application needs.</li>
<li>A text editor and a browser.</li>
</ul>

<p>Open a terminal (for example, <em>Terminal &rarr; New Terminal</em> in VS Code) and run these commands, one line at a time:</p>

[[bash]]
php --version
composer --version
php -m
[[/bash]]

<p>The first two commands should print version numbers. The last lists enabled PHP extensions; look for the four listed above. If <code>php</code> or <code>composer</code> is not recognised, install it and reopen your terminal before continuing. <code>php --ini</code> shows which configuration file your command-line PHP uses.</p>

<div class="note">
<b>How to read the commands</b>
<p>Type the commands into your terminal, not into a PHP file. Code blocks here omit the prompt character so you can copy them directly. Run each next command only after the previous one succeeds. We use <code>php orbit</code> for project commands: it works in Windows PowerShell, macOS and Linux. Other pages use <code>./orbit</code>, which is the macOS/Linux shorthand for the same script. You do not need <code>pcntl</code> or the <code>sockets</code> extension for the built-in server; it uses PHP streams, with optional signal handling when <code>pcntl</code> is available.</p>
</div>

<h2 id="create-project">2. Create a blank project</h2>

<p>Install the CLI (command-line interface) once:</p>

[[bash]]
composer global require phporbit/phporbit
composer global config bin-dir --absolute
[[/bash]]

<p>The second command prints Composer's global executable directory. Add that directory to your user's <code>PATH</code> if <code>orbit</code> is not found. <code>PATH</code> is the list of folders your terminal searches for commands.</p>

<p>On <strong>Windows</strong>, open <em>Edit environment variables for your account</em>, edit <em>Path</em>, add the printed directory, then open a new terminal. On <strong>macOS/Linux</strong>, run the following, and add the same line to your shell profile to keep it for future terminals:</p>

[[bash]]
export PATH="$(composer global config bin-dir --absolute):$PATH"
[[/bash]]

<p>In the folder where you keep your projects, run:</p>

[[bash]]
orbit new my-app
cd my-app
composer install
php orbit migrate
[[/bash]]

<p><code>orbit new</code> creates a folder named <code>my-app</code> containing your application. <code>cd</code> moves your terminal into that folder, and <code>composer install</code> downloads its dependencies into <code>vendor/</code>. <code>migrate</code> applies database schema changes (called <em>migrations</em>); the blank application has no application tables yet, which is normal.</p>

<p>The generator also creates <code>.env</code>, your local configuration file, and gives this project its own <code>APP_KEY</code> for signing and encryption. <strong>Do not copy <code>.env.example</code> over it</strong>, share its key or commit <code>.env</code> to Git. Keep the default SQLite settings for this tutorial.</p>

<div class="note">
<b>Blank project, demo, or framework checkout?</b>
<p>Use <code>orbit new my-demo --demo</code> in a different folder if you want a working example with sign-in, notes, uploads and a self-check page. After <code>composer install</code> and <code>php orbit migrate</code> in that project, run <code>php orbit db:seed</code> to create its demo account: <code>demo@example.test</code> / <code>correct-horse-battery</code>. These are local demo credentials, not production credentials. If you cloned phporbit itself, use the <a href="#framework-checkout">checkout instructions below</a> instead of scaffolding over it.</p>
</div>

<h2 id="run-project">3. Start the application</h2>

<p>From inside <code>my-app</code> (the folder containing <code>orbit</code> and <code>composer.json</code>), run:</p>

[[bash]]
php orbit serve --debug
[[/bash]]

<p>Wait for the message saying phporbit is listening, then open <a href="http://127.0.0.1:8080">http://127.0.0.1:8080</a> in your browser. You should see the starter welcome page with the heading <strong>It works</strong>. <code>127.0.0.1</code> means your own computer; <code>8080</code> is the port the server listens on.</p>

<p>The terminal stays busy because it is running the server. Leave it open and use a second terminal in the same project folder for other commands. To stop the server, return to its terminal and press <strong>Ctrl+C</strong>.</p>

<div class="warn">
<b>Restart after PHP or configuration changes</b>
<p>The built-in server keeps your application running between requests. After changing routes, controllers, other PHP classes or <code>.env</code>, stop it with Ctrl+C and run <code>php orbit serve --debug</code> again. Refreshing the browser alone does not reload that code. Template edits are picked up on refresh in debug mode. Debug mode shows exception details: use it locally, never on a public deployment.</p>
</div>

<h2 id="first-route">4. Add a first route</h2>

<p>A <strong>route</strong> connects a URL and an HTTP method to PHP code. <code>GET</code> is the method a browser normally uses when opening a page.</p>

<p>Open <code>app/routes.php</code>. It already imports <code>Response</code> and <code>RouteCollection</code>. Add this line <strong>inside the existing <code>return static function (...) { ... };</code> block</strong>, before its closing <code>};</code>. Keep the existing routes:</p>

[[php]]
$routes->get('/ping', static fn (): Response => Response::json(['pong' => true]), 'ping');
[[/php]]

<p><code>'/ping'</code> is the URL path, the function returns a JSON response, and the final <code>'ping'</code> is a route name you can use when generating links later. JSON is a text format for structured data.</p>

<p>Save the file, restart the server, then open <a href="http://127.0.0.1:8080/ping">http://127.0.0.1:8080/ping</a>. The response contains:</p>

[[text]]
{"pong":true}
[[/text]]

<p>In your second terminal, <code>php orbit routes</code> should also list <code>/ping</code>. This helps distinguish a missing route from a server that has not been restarted.</p>

<h2 id="first-controller">5. Generate a controller and template</h2>

<p>A <strong>controller</strong> is a PHP class that handles a request and returns a response. A <strong>template</strong> holds the HTML for a page. Keeping them separate lets you change a page's appearance without mixing it with application logic.</p>

<p>In the second terminal, run:</p>

[[bash]]
php orbit make:controller Greet --view
[[/bash]]

<p>This creates <code>app/src/Controllers/GreetController.php</code> and <code>app/templates/greet.orbit.php</code>. It prints a route and an import, but <strong>does not add them to your route file</strong>. We will add a route with a name parameter in the next step.</p>

<p>Replace the generated controller's contents with this complete file:</p>

[[php]]
<?php

declare(strict_types=1);

namespace App\Controllers;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\View\TemplateEngine;

final class GreetController implements Handler
{
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

<ul>
<li><code>namespace App\Controllers</code> identifies where this class belongs; <code>use</code> lines import the class names it needs.</li>
<li><code>Handler</code> requires a <code>handle()</code> method. phporbit calls it when the route matches.</li>
<li><code>ServerRequest</code> holds information about the incoming request, including route parameters.</li>
<li>The constructor asks for <code>TemplateEngine</code>. phporbit supplies it automatically; you do not construct it yourself. This is called <em>dependency injection</em>.</li>
<li><code>respond('greet', ...)</code> renders <code>greet.orbit.php</code> as an HTML response. The array passes <code>$title</code> and <code>$name</code> to the template.</li>
</ul>

<h2 id="connect-controller">6. Connect the controller to a URL</h2>

<p>In <code>app/routes.php</code>, add this import alongside the existing <code>use</code> lines, <strong>above</strong> <code>return static function</code>:</p>

[[php]]
use App\Controllers\GreetController;
[[/php]]

<p>Then add this route <strong>inside</strong> that function, alongside the existing routes:</p>

[[php]]
$routes->get('/greet/{name}', GreetController::class, 'greet');
[[/php]]

<p><code>{name}</code> captures one URL segment. For <code>/greet/Ada</code>, the controller reads <code>Ada</code> through <code>$request->attribute('name')</code>. Use this parameterised route instead of the generator's suggested <code>/greet</code> route.</p>

<h2 id="first-template">7. Write the page's HTML</h2>

<p>Replace <code>app/templates/greet.orbit.php</code> with:</p>

[[html]]
@extends('layout')

@section('content')
<div class="prose">
    <h1>Hello, {{ $name }}!</h1>
    <p>You built a page with a route, a controller and a template.</p>
    <p><a href="/">Back to home</a></p>
</div>
@endsection
[[/html]]

<p><code>@extends('layout')</code> uses the existing <code>app/templates/layout.orbit.php</code>, which supplies the surrounding page. <code>@section('content')</code> fills that layout's content area. <code>{{ $name }}</code> prints the name with HTML escaping, so input is displayed as text instead of being treated as HTML. Keep this escaped form for user-provided values.</p>

<p>Save both files and your route changes, restart the server, then open <a href="http://127.0.0.1:8080/greet/Ada">http://127.0.0.1:8080/greet/Ada</a>. You should see <strong>Hello, Ada!</strong> Try <code>/greet/Sam</code> to see the same controller and template render a different name.</p>

<p>Now change the sentence in the template and refresh. With <code>--debug</code>, this template-only edit does not need a restart. Note that <code>/greet</code> by itself returns 404: the route requires a name segment.</p>

<div class="good">
<b>What you just built</b>
<p>Browser requests <code>GET /greet/Ada</code> &rarr; router selects <code>GreetController</code> &rarr; controller passes <code>Ada</code> to the template &rarr; template becomes an HTML response &rarr; browser displays the page.</p>
</div>

<h2 id="project-layout">Where things go</h2>

<div class="scroller">
<table>
<thead><tr><th>Path</th><th>What to put there</th></tr></thead>
<tbody>
<tr><td><code>app/routes.php</code></td><td>Connections between URLs and handlers</td></tr>
<tr><td><code>app/src/Controllers/</code></td><td>Your request-handling classes, under <code>App\Controllers</code></td></tr>
<tr><td><code>app/templates/</code></td><td>HTML templates ending in <code>.orbit.php</code></td></tr>
<tr><td><code>app/bootstrap.php</code></td><td>Startup configuration: services and middleware (layers around handlers)</td></tr>
<tr><td><code>database/migrations/</code></td><td>Versioned changes to database tables</td></tr>
<tr><td><code>public/</code></td><td>Browser-accessible assets and the production web entrypoint; keep secrets out</td></tr>
<tr><td><code>storage/</code></td><td>Generated files: sessions, compiled templates and the SQLite database</td></tr>
<tr><td><code>.env</code></td><td>Local settings and secrets; never commit it</td></tr>
<tr><td><code>vendor/</code></td><td>Composer-managed dependencies; do not edit them</td></tr>
</tbody>
</table>
</div>

<h2 id="troubleshooting">If something goes wrong</h2>

<div class="scroller">
<table>
<thead><tr><th>Symptom</th><th>What to check</th></tr></thead>
<tbody>
<tr><td><code>orbit</code> is not recognised</td><td>Check Composer's global bin directory with <code>composer global config bin-dir --absolute</code>, add it to PATH, and open a new terminal.</td></tr>
<tr><td><code>Could not open input file: orbit</code></td><td>Move into your project folder with <code>cd my-app</code>. You should see <code>orbit</code> and <code>composer.json</code> there.</td></tr>
<tr><td>Missing <code>vendor/autoload.php</code> or Composer autoloader</td><td>Run <code>composer install</code> in that project folder and resolve any errors it reports.</td></tr>
<tr><td><code>could not find driver</code></td><td>Enable <code>pdo_sqlite</code> in the PHP configuration shown by <code>php --ini</code>. Check <code>php -m</code>, then restart the server.</td></tr>
<tr><td>Port 8080 is already in use</td><td>Run <code>php orbit serve --port=9000 --debug</code> and open <code>http://127.0.0.1:9000</code> instead.</td></tr>
<tr><td>The browser cannot connect</td><td>Check that the server terminal is still running and says it is listening. Match the URL's port to the server's port.</td></tr>
<tr><td>404 or an old version of a page</td><td>Check the exact URL and <code>php orbit routes</code>. Restart after PHP changes; <code>/greet/Ada</code> matches, but <code>/greet</code> does not.</td></tr>
<tr><td>500 / Internal Server Error</td><td>Use <code>--debug</code> locally, retry the request and read the error and server terminal. Check imports, spelling and semicolons. Do not turn on debug on a public server.</td></tr>
<tr><td>The generator says a file exists</td><td>Edit that file rather than regenerating it. <code>--force</code> overwrites work; only use it if you intend to replace the file.</td></tr>
</tbody>
</table>
</div>

<h2 id="framework-checkout">Alternative: run the framework's demo checkout</h2>

<p>This path is for a clone of the phporbit repository, not the <code>my-app</code> project above. From the repository root, run <code>composer install</code>. If <code>.env</code> does not already exist, copy <code>.env.example</code> to <code>.env</code> in your editor (PowerShell: <code>Copy-Item .env.example .env</code>; macOS/Linux: <code>cp .env.example .env</code>).</p>

<p>If <code>APP_KEY</code> in <code>.env</code> is blank, generate a key:</p>

[[bash]]
php orbit key:generate
[[/bash]]

<p>This prints a new key; it does not update a file. Paste the entire printed <code>base64:...</code> value after <code>APP_KEY=</code> in <code>.env</code>. Keep an existing key rather than replacing it: previously encrypted data still needs it. Then run:</p>

[[bash]]
php orbit migrate
php orbit db:seed
php orbit serve --debug
[[/bash]]

<p>Open <code>http://127.0.0.1:8080</code> for the demo self-check page. Sign in with <code>demo@example.test</code> / <code>correct-horse-battery</code>. The checkout also serves these docs at <code>/docs</code>; generated applications do not include a documentation copy or a <code>/docs</code> route.</p>

<h2 id="next-steps">What to learn next</h2>

<ol>
<li><a href="routing.html">Routing</a> and <a href="controllers.html">controllers</a>: more URL patterns and request handlers.</li>
<li><a href="templates.html">Templates</a>: layouts, loops and reusable page parts.</li>
<li><a href="forms.html">Forms</a>: render inputs, validate submissions and keep CSRF protection enabled.</li>
<li><a href="database.html">Database</a>, <a href="migrations.html">migrations</a> and <a href="models.html">models</a>: create tables and store application data.</li>
<li><a href="testing.html">Testing</a>: check your application with <code>composer test</code> and <code>composer stan</code>.</li>
<li><a href="deployment.html">Deployment</a>: publish using FrankenPHP, nginx + PHP-FPM or Apache, with debug disabled. The built-in server is for local development.</li>
</ol>

<p>Prefer a local dashboard for generators and migrations? Run <code>php orbit ui</code> in a second terminal and visit <code>http://127.0.0.1:8081</code>. <strong>It has no login: keep it bound to the default <code>127.0.0.1</code> and do not expose it publicly.</strong> See <a href="admin-ui.html">Admin UI</a> and the <a href="cli.html">CLI command reference</a>.</p>

<p>Before adding services that hold state, read <a href="container.html">service lifetimes</a> and <a href="architecture.html">architecture</a>. In plain terms: a running worker serves many visitors, so never store the current visitor's data in static properties or singleton services. Use phporbit's request-scoped services and sessions instead of PHP's global session functions.</p>
HTML,
];
