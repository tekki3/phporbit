<?php

declare(strict_types=1);

return [
    'slug' => 'cli',
    'title' => 'The orbit CLI',
    'summary' => 'Every command, what it does, and how to add one of your own.',
    'body' => <<<'HTML'
[[bash]]
$ ./orbit help
phporbit

Usage:
  orbit new <directory> [--demo] [--force]
  orbit key:generate
  orbit make:class <Name> [--singleton|--scoped] [--force]
  orbit make:controller <Name> [--view] [--force]
  orbit make:form <Name> [--fields=a:text,b:email] [--captcha]
                         [--no-honeypot] [--controllers] [--force]
  orbit make:middleware <Name> [--force]
  orbit make:migration <name> [--table=x] [--sequential] [--force]
  orbit serve [--host=127.0.0.1] [--port=8080] [--debug]
  orbit ui [--host=127.0.0.1] [--port=8081] [--debug]
  orbit routes
  orbit migrate
  orbit migrate:status
  orbit migrate:rollback [--batches=1]
  orbit db:seed
  orbit mail:test --to=x@example.test [--from=x@example.test]
  orbit mail:list [--status=sent|failed] [--limit=20]
  orbit mail:resend <id>|--failed [--limit=50]
  orbit storage:clear
  orbit sessions:gc
  orbit help
[[/bash]]

<h2>new</h2>

<p>Creates a project. Two shapes, both complete and runnable.</p>

[[bash]]
$ orbit new my-app
Writing entrypoints, configuration and tooling
Writing a blank application — one route, one controller, one template
Copied .env.example to .env
Created my-app — a blank application — one route, one controller, one template

  20 files written

Next:
  cd my-app
  composer install
  ./orbit migrate
  ./orbit serve
[[/bash]]

<div class="scroller">
<table>
<thead><tr><th>Variant</th><th>What you get</th></tr></thead>
<tbody>
<tr><td><em>default</em></td><td>A blank application: one route, one controller, one template, a starter test, no tables.</td></tr>
<tr><td><code>--demo</code></td><td>The demo application: sessions, authentication, uploads, notes and the live self-check page.</td></tr>
</tbody>
</table>
</div>

<p>Both include the entrypoints for all four deployment targets, a configured <code>.env</code>, PHPUnit and PHPStan, and a <code>README.md</code>.</p>

<div class="note">
<b>Neither includes this documentation</b>
<p>Docs belong to the framework, not to every project built on it — a copy in each application is a copy that goes stale. <code>app/bootstrap.php</code> mounts <code>/docs</code> only when the directory exists, so a scaffolded project simply has no such route and no dead link to it.</p>
</div>

<h3>It refuses to overwrite</h3>

[[bash]]
$ orbit new existing-project
Directory "existing-project" is not empty (13 entries). Pass --force to write into it anyway.
[[/bash]]

<p>Scaffolding over a project would replace its bootstrap and routes without warning, so an occupied directory is an error rather than a merge. <code>--force</code> writes anyway, leaving files the scaffold does not itself produce — including an existing <code>.env</code> — untouched.</p>

<h3>After scaffolding</h3>

[[bash]]
$ cd my-app
$ composer install
$ ./orbit migrate       # --demo also wants: ./orbit db:seed
$ ./orbit serve
[[/bash]]

<h2>make:class</h2>

<p>Writes a plain class under <code>App\</code> — a repository, a service, a form definition, a small value object: everything an application holds that is neither a controller nor a migration.</p>

[[bash]]
$ orbit make:class Notes/NoteRepository
Created app/src/Notes/NoteRepository.php

App\Notes\NoteRepository — autowired per request, so nothing to register

Inject it where you need it:

  use App\Notes\NoteRepository;
  private readonly NoteRepository $noteRepository,
[[/bash]]

<p>That is the whole story for the default: an unregistered class is constructed by the <code>RequestScope</code> when something asks for it, and discarded when the request ends. A controller naming it as a constructor parameter gets one — no bootstrap edit, and no way for it to outlive the request that built it.</p>

<h3>The lifetime is the argument</h3>

<p>It is the one decision a new class here cannot avoid, because a long-lived worker shares anything that outlives a request with the next visitor. So it is a flag on the command rather than something to discover later:</p>

<div class="scroller">
<table>
<thead><tr><th>Flag</th><th>Lifetime</th><th>For</th></tr></thead>
<tbody>
<tr><td><em>default</em></td><td>Autowired per request, registered nowhere</td><td>Almost everything. Dependencies must be object-typed.</td></tr>
<tr><td><code>--scoped</code></td><td>Rebuilt for every request</td><td>Per-request state, or anything needing the session or the current user.</td></tr>
<tr><td><code>--singleton</code></td><td>One instance per process</td><td>Connections, compiled tables, configuration — and it must be stateless.</td></tr>
</tbody>
</table>
</div>

<p>The two flags name one lifetime each, so passing both is refused rather than resolved silently. With either, the registration line is printed for <code>app/bootstrap.php</code>:</p>

[[bash]]
$ orbit make:class Support/Clock --singleton
Created app/src/Support/Clock.php

App\Support\Clock — a singleton: one instance per process, so it must be stateless

Add to app/bootstrap.php:

  use App\Support\Clock;
  $app->container->singleton(Clock::class, static fn (): Clock => new Clock());

Inject it where you need it:

  use App\Support\Clock;
  private readonly Clock $clock,
[[/bash]]

<p>The class comment states the constraint that lifetime carries, so it is in front of you while you write the methods — not in a document you read once:</p>

[[php]]
<?php
namespace App\Support;

/**
 * A singleton: one instance for the whole process.
 *
 * Under a long-lived worker — this framework's own server and FrankenPHP —
 * that one instance is shared by every request the process serves, so it
 * must be stateless. Anything mutable stored on it leaks from one visitor
 * to the next, and the leak is invisible under Apache or nginx+FPM, where
 * the process dies after each response.
 *
 * Hold connections, compiled tables and configuration here. Hold request
 * data in a scoped class instead.
 */
final class Clock
{
}
[[/php]]

<h3>Names</h3>

<div class="scroller">
<table>
<thead><tr><th>You type</th><th>You get</th></tr></thead>
<tbody>
<tr><td><code>Clock</code></td><td><code>App\Clock</code> in <code>app/src/Clock.php</code></td></tr>
<tr><td><code>Notes/NoteRepository</code></td><td><code>App\Notes\NoteRepository</code>, nested to match</td></tr>
<tr><td><code>App\Notes\NoteRepository</code></td><td>the same — a leading <code>App</code> is not repeated</td></tr>
</tbody>
</table>
</div>

<p>StudlyCase, like <code>make:controller</code>, and for the same reason: a name arriving from a shell argument must never place a file outside <code>app/src</code>. Reserved words are refused too, because writing a file PHP cannot parse is worse than answering the question:</p>

[[bash]]
$ orbit make:class List
Invalid class name "List": "List" is a reserved word in PHP and cannot name a class or a namespace.
[[/bash]]

<p>An existing file is never overwritten without <code>--force</code>, and the bootstrap file is never edited — the registration line is printed for you to paste, exactly as <code>make:controller</code> prints its route.</p>

<h2>make:controller</h2>

<p>Writes a controller class, and optionally the template it renders.</p>

[[bash]]
$ orbit make:controller Reports
Created app/src/Controllers/ReportsController.php

Add to app/routes.php:

  use App\Controllers\ReportsController;
  $routes->get('/reports', ReportsController::class, 'reports');
[[/bash]]

<p>With <code>--view</code> it also writes the template and injects the engine:</p>

[[bash]]
$ orbit make:controller Admin/UserProfile --view
Created app/src/Controllers/Admin/UserProfileController.php
Created app/templates/admin/user-profile.orbit.php

Add to app/routes.php:

  use App\Controllers\Admin\UserProfileController;
  $routes->get('/admin/user-profile', UserProfileController::class, 'admin.user-profile');
[[/bash]]

[[php]]
<?php
namespace App\Controllers\Admin;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\View\TemplateEngine;

final class UserProfileController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->view->respond('admin/user-profile', [
            'title' => 'User Profile',
        ]);
    }
}
[[/php]]

<h3>Names</h3>

<div class="scroller">
<table>
<thead><tr><th>You type</th><th>You get</th></tr></thead>
<tbody>
<tr><td><code>Reports</code></td><td><code>App\Controllers\ReportsController</code></td></tr>
<tr><td><code>ReportsController</code></td><td>the same — the suffix is added once, never twice</td></tr>
<tr><td><code>Admin/Users</code></td><td><code>App\Controllers\Admin\UsersController</code>, template <code>admin/users</code></td></tr>
</tbody>
</table>
</div>

<p>Names must be StudlyCase. Anything else is refused rather than cleaned up — a name arriving from a shell argument must never be able to place a file outside <code>app/src/Controllers</code>:</p>

[[bash]]
$ orbit make:controller ../evil
Invalid controller name "../evil". Use StudlyCase, optionally nested: Home, UserProfile, Admin/Users.
[[/bash]]

<p>An existing file is never overwritten without <code>--force</code>.</p>

<div class="note">
<b>It does not edit your routes file</b>
<p>The route line is printed for you to paste. Rewriting a file you own means parsing and re-emitting your code, and getting that subtly wrong is worse than leaving one line to add — in the place where you can see it.</p>
</div>

<h2>make:form</h2>

<p>Writes a form definition: one class whose <code>build()</code> returns the <code>Form</code>. That single declaration is both the markup and the validation, which is the property worth generating — a form written by hand is where a field ends up rendered but never checked, because the input and the rule live in different files and only one of them got updated.</p>

[[bash]]
$ orbit make:form Contact --controllers
Created app/src/Forms/ContactForm.php
Created app/src/Controllers/ContactController.php
Created app/src/Controllers/SubmitContactController.php
Created app/templates/contact.orbit.php

App\Forms\ContactForm — 3 fields: name, email, message

Add to app/routes.php:

  use App\Controllers\ContactController;
  use App\Controllers\SubmitContactController;
  $routes->get('/contact', ContactController::class, 'contact');
  $routes->post('/contact', SubmitContactController::class, 'contact.submit');
[[/bash]]

<p>Paste those two lines and the page works: it renders, validates, redisplays what was typed when a rule fails, and redirects after a successful submission. Without <code>--controllers</code> only the definition is written, for a page you are writing yourself.</p>

[[php]]
<?php
namespace App\Forms;

use PhpOrbit\Crypto\Signer;
use PhpOrbit\Form\Field;
use PhpOrbit\Form\Form;
use PhpOrbit\Form\Honeypot;

final class ContactForm
{
    public function __construct(
        private readonly Signer $signer,
    ) {
    }

    public function build(): Form
    {
        return Form::post('/contact')
            ->add(
                Field::text('name')->required()->max(120),
                Field::email('email')->required()->max(120),
                Field::textarea('message')->required()->max(2000),
            )
            // The decoy and the signed clock ask a person for nothing and
            // stop the scripts that post to every form they find.
            ->protectWith(new Honeypot($this->signer));
    }
}
[[/php]]

<p>Nothing registers this class: its dependencies are singletons the bootstrap already defines, so the request scope autowires it into both controllers. The CSRF token is not in the declaration either — a <code>post()</code> form emits one because it is a POST, not because someone remembered.</p>

<h3>Fields</h3>

[[bash]]
$ orbit make:form Signup --fields=email:email,password:password,plan:select,terms:checkbox
[[/bash]]

<p>Written as <code>name:type</code>, with <code>text</code> assumed when the type is left off. The available types are the ones <code>Field</code> has a factory for: <code>text</code>, <code>email</code>, <code>password</code>, <code>number</code>, <code>url</code>, <code>tel</code>, <code>date</code>, <code>checkbox</code>, <code>textarea</code>, <code>select</code>. The default set is <code>name:text,email:email,message:textarea</code>.</p>

<p>Each field arrives with rules attached — <code>required()</code>, a length bound, and <code>min(12)->max(72)</code> on a password because 72 bytes is where bcrypt truncates and <code>PasswordHasher</code> refuses more. The particular numbers are a starting point; that the rules <em>exist</em> is the point.</p>

<h3>Protections are on by default</h3>

<div class="scroller">
<table>
<thead><tr><th>Flag</th><th>Effect</th></tr></thead>
<tbody>
<tr><td><em>default</em></td><td><code>Honeypot</code>: a decoy field and a signed render clock. Costs a visitor nothing.</td></tr>
<tr><td><code>--captcha</code></td><td>Adds <code>MathCaptcha</code> — arithmetic, no JavaScript, no third party.</td></tr>
<tr><td><code>--no-honeypot</code></td><td>Leaves both off, for a form behind a login.</td></tr>
</tbody>
</table>
</div>

<p>A protection that has to be added afterwards is one that gets added after the first spam run, so the default path is the protected one. Be clear about what the captcha buys, though: it stops undirected scripts, not someone who has decided to attack you — a language model solves arithmetic.</p>

<h3>Refusals</h3>

[[bash]]
$ orbit make:form Contact --fields=website:url
Field "website" clashes with the honeypot's decoy field — rename it, or pass --no-honeypot.

$ orbit make:form Contact --fields=name:wat
Unknown field type "wat" for "name". Available: text, email, password, number, url, tel, date, checkbox, textarea, select.
[[/bash]]

<p>The first one matters more than it looks. A field named <code>website</code> beside the honeypot means every real visitor fills the decoy, so every genuine submission is rejected as automated — and the generic message they get explains nothing. The clash is silent at runtime, which is why it is answered here. The same applies to <code>_token</code>, <code>_rendered</code> and the captcha's fields, and to declaring one name twice.</p>

<p>Names follow <code>make:controller</code>: StudlyCase, optionally nested. <code>Contact</code> and <code>ContactForm</code> both give <code>ContactForm</code>; <code>Admin/Invite</code> nests the namespace, the template (<code>admin/invite</code>) and the route (<code>/admin/invite</code>). Because the action and the printed route come from one derivation, the form cannot post somewhere the route does not answer.</p>

<p>An existing file is never overwritten without <code>--force</code> — and every target is checked <em>before</em> the first is written, since a half-generated slice is worse than none.</p>

<h2>make:middleware</h2>

<p>Writes a class implementing <code>Middleware</code>, with a body that passes the request straight through:</p>

[[bash]]
$ orbit make:middleware RequestId
Created app/src/Middleware/RequestIdMiddleware.php

App\Middleware\RequestIdMiddleware

Add to the $app->middleware(...) list in app/bootstrap.php:

  use App\Middleware\RequestIdMiddleware;
  new RequestIdMiddleware(),
[[/bash]]

<div class="note">
<b>It does not edit your middleware list</b>
<p>Unlike a controller or an autowired class, middleware is never resolved by the container — <code>$app->middleware(...)</code> takes constructed objects directly, in the exact order they run. That order is meaning, not plumbing: <code>SessionMiddleware</code> has to precede <code>CsrfMiddleware</code>, which reads the session it publishes. Inserting a line automatically would mean guessing where it belongs, so the entry is printed for you to place instead — the same reasoning as the route line <code>make:controller</code> leaves to paste.</p>
</div>

<p>The generated class:</p>

[[php]]
<?php
namespace App\Middleware;

use Closure;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Middleware\Middleware;

final class RequestIdMiddleware implements Middleware
{
    public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
    {
        return $next($request);
    }
}
[[/php]]

<p>Names follow <code>make:controller</code>: StudlyCase, optionally nested by <code>/</code>, with the <code>Middleware</code> suffix added once whether or not it was typed. Reserved words are refused for the same reason as <code>make:class</code> — writing a file PHP cannot parse is worse than answering the question at the command line.</p>

<h2>make:migration</h2>

[[bash]]
$ orbit make:migration create_articles_table
Created database/migrations/20260811143012_create_articles_table.php

Edit it, then run: orbit migrate
[[/bash]]

<p>The name decides the starting contents. That inference is only a convenience — every shape produces a valid, portable migration.</p>

<div class="scroller">
<table>
<thead><tr><th>Name</th><th>What you get</th></tr></thead>
<tbody>
<tr><td><code>create_articles_table</code></td><td><code>CREATE TABLE articles</code>, with <code>DROP TABLE</code> to reverse it</td></tr>
<tr><td><code>add_slug_to_articles</code></td><td><code>ALTER TABLE articles</code>, both ways</td></tr>
<tr><td><code>backfill_search_index</code></td><td>An empty <code>up()</code>/<code>down()</code> pair with guidance</td></tr>
</tbody>
</table>
</div>

<p><code>CreateArticlesTable</code> and <code>create articles table</code> normalise to the same thing. <code>--table=x</code> overrides whatever the name implied.</p>

[[php]]
<?php
// The generated create-table migration
return new class implements Migration {
    public function up(Connection $database): void
    {
        $database->executeSchema(sprintf(
            'CREATE TABLE articles (
                id %s,
                created_at TEXT NOT NULL
            )',
            $database->driver()->autoIncrementPrimaryKey(),
        ));
    }

    public function down(Connection $database): void
    {
        $database->executeSchema('DROP TABLE articles');
    }
};
[[/php]]

<p>Note the primary key: generated migrations are portable across SQLite, MySQL and PostgreSQL from the start, because that is the one part of the schema the three engines spell differently.</p>

<h3>The filename prefix</h3>

<p>Migrations run in filename order, so the prefix decides the order. The default is a timestamp, which is what lets two developers on separate branches add migrations without coordinating — they get different numbers, and merging is deterministic.</p>

[[bash]]
$ orbit make:migration create_tags_table --sequential
Created database/migrations/0004_create_tags_table.php
[[/bash]]

<p><code>--sequential</code> continues the <code>0001</code>, <code>0002</code> counter instead, which reads better in a small repository where collisions are not a concern.</p>

<h3>Refusals</h3>

[[bash]]
$ orbit make:migration ../evil
Invalid migration name "../evil". A migration is named in words, not by path — try create_articles_table.

$ orbit make:migration create_things --table='a; DROP TABLE users'
Invalid table name "a; DROP TABLE users". Identifiers may contain letters, digits and underscores.
[[/bash]]

<p>Punctuation and casing in a <em>name</em> are normalised freely, because a name is words. A path separator is not: it means the caller was aiming somewhere, and quietly rewriting it would hide that rather than answer it. The table name is validated rather than escaped, because no driver can bind an identifier — it reaches the SQL directly.</p>

<h2>serve</h2>

[[bash]]
$ ./orbit serve
phporbit listening on http://127.0.0.1:8080 (production mode) — Ctrl-C to stop
GET / -> 200

$ ./orbit serve --port=9000 --debug
$ ./orbit serve --host=0.0.0.0 --port=8080
[[/bash]]

<p>Starts phporbit's own HTTP/1.1 server — a long-lived process sharing the exact pipeline used in production, so a state leak shows up on your machine rather than in production.</p>

<p><code>--debug</code> sets <code>APP_DEBUG</code> in the process environment, which exposes exception detail in responses and recompiles templates on every render. Pending migrations are applied first, as a development convenience.</p>

<div class="warn">
<b>Binding to 0.0.0.0</b>
<p>That exposes the server to your whole network. It serves connections sequentially in one process, which is fine for development and exactly why it is not a production target — one slow request blocks every other. Use FrankenPHP, nginx+FPM or Apache for anything real.</p>
</div>

<h2>ui</h2>

[[bash]]
$ ./orbit ui
Starting the admin UI — migrations, mail, routes, sessions, storage.
phporbit listening on http://127.0.0.1:8081 (production mode) — Ctrl-C to stop
[[/bash]]

<p>A second, self-contained web application — never wired into <code>app/routes.php</code> — for running migrations, resending failed mail, browsing routes, sessions and the template cache, and every <code>make:*</code> generator on this page, from a browser instead of a terminal. Binds to <code>127.0.0.1</code> by default and warns if told to bind anywhere else: there is no login, so treat it like a database console left open on your own machine. Full write-up, including why it is a separate application rather than a few extra routes: <a href="admin-ui.html">The admin UI</a>.</p>

<h2>routes</h2>

[[bash]]
$ ./orbit routes
GET     /                                        self-check
POST    /articles                                articles.store
GET     /articles/{id:\d+}                       articles.show
GET     /health                                  health
[[/bash]]

<p>The compiled table — method, pattern, name — sorted by path. This is what the router will actually match, so it is the fastest way to check whether a route landed where you expected.</p>

<h2>migrate</h2>

[[bash]]
$ ./orbit migrate
Applying pending migrations...
  0003_create_articles
  0004_add_articles_slug

$ ./orbit migrate
Nothing to migrate.
[[/bash]]

<p>Applies everything pending, each in its own transaction, grouped into one batch. Run it as a deploy step: the production entrypoints deliberately never touch the schema, because several workers booting at once would race.</p>

<h2>migrate:status</h2>

[[bash]]
$ ./orbit migrate:status
  applied   0001_create_users            batch 1
  applied   0002_create_auth_attempts    batch 1
  applied   0003_create_articles         batch 2
  pending   0004_add_articles_slug
[[/bash]]

<h2>migrate:rollback</h2>

[[bash]]
$ ./orbit migrate:rollback
Reversed 0003_create_articles

$ ./orbit migrate:rollback --batches=2
[[/bash]]

<p>Reverses the most recent batch — one deployment's worth of changes. Every migration in the batch is loaded and checked before anything is undone, so a batch containing an irreversible migration fails without half-rolling-back the rest.</p>

<h2>db:seed</h2>

[[bash]]
$ ./orbit db:seed
Seeded demo account: demo@example.test / correct-horse-battery
[[/bash]]

<p>Idempotent — safe to re-run. Seed data is not a migration, because it is not a schema change and should not be part of a rollback.</p>

<h2>mail:test</h2>

<p>Sends one message through whatever <code>MAIL_DRIVER</code> is actually configured. Worth being direct about why this exists: nothing about <code>SmtpSettings</code> or <code>SmtpSession</code> has ever been exercised against a real mail server as part of building this framework — see <a href="mail.html">Sending email</a> — so this is the first thing that proves an SMTP configuration works, rather than merely parses.</p>

[[bash]]
$ ./orbit mail:test --to=you@example.test
Accepted by the "array" driver — nothing left this machine. Set MAIL_DRIVER=smtp to test real delivery.

$ MAIL_DRIVER=smtp ./orbit mail:test --to=you@example.test
Sent to you@example.test via smtp.

$ ./orbit mail:test --to=you@example.test
Send failed (driver: smtp): Could not connect to tcp://smtp.example.test:587: ...
[[/bash]]

<p>The sender comes from <code>MAIL_FROM_ADDRESS</code> by default; <code>--from=</code> overrides it, and the command refuses outright if neither is set — a test send needs a sender the same as any other. Read directly from configuration rather than left to the mailer's own default, because only <code>SmtpMailer</code> applies one; <code>mail:test</code> has to behave the same way regardless of driver.</p>

<p>The send goes through the same <code>Mailer</code> every controller uses — see <a href="mail.html">Every send is persisted</a> — so a test message is one more row in <code>orbit mail:list</code>, and a failed one is resendable with <code>orbit mail:resend</code> once whatever was wrong is fixed, without composing a new message by hand.</p>

<h2>mail:list</h2>

[[bash]]
$ ./orbit mail:list
5     sent    2    carol@example.test                 Reminder 2                               2026-01-01T09:14:11+00:00
4     failed  1    bob@example.test                   Reminder                                 2026-01-01T09:14:02+00:00
3     sent    1    grace@example.test                 Receipt                                  2026-01-01T09:12:44+00:00

$ ./orbit mail:list --status=failed --limit=5
[[/bash]]

<p>Every message sent through <code>Mailer</code> is recorded — see <a href="mail.html">Sending email</a> for what <code>mail_log</code> holds — and this is how to read that log back: id, status, attempts, recipients, subject, last-updated, most recent first. <code>--status</code> narrows to <code>sent</code> or <code>failed</code>; <code>--limit</code> defaults to 20.</p>

<h2>mail:resend</h2>

[[bash]]
$ ./orbit mail:resend 4
Resent #4 to bob@example.test.

$ ./orbit mail:resend --failed
2 resent, 0 still failing.

$ ./orbit mail:resend 3
Mail #3 has status "sent"; only failed mail can be resent.
[[/bash]]

<p>Takes an id, or <code>--failed</code> to resend everything currently failed (bounded by <code>--limit</code>, default 50). Only <code>failed</code> mail can be resent — a message already marked <code>sent</code> is refused, because resending it would deliver it twice with no record that either send happened. A resend that fails again updates the same row rather than adding a new one; <code>attempts</code> grows and the error moves to the latest attempt.</p>

<p><code>--failed</code> does not stop at the first failure: it works through every entry and reports how many were resent successfully and how many are still failing, exiting non-zero only if at least one remains failed — the shape a cron entry or a deploy step would check.</p>

<h2>storage:clear</h2>

[[bash]]
$ ./orbit storage:clear
Removed 14 compiled templates.

$ ./orbit storage:clear
Removed 0 compiled templates.
[[/bash]]

<p>Deletes everything under <code>storage/cache/views</code> — see <a href="templates.html">Templates</a> for what lives there. Safe to run at any time: <code>TemplateEngine</code> recompiles a template the moment it finds no compiled file waiting for it, so the very next render replaces whatever this removed. Nothing else in <code>storage/</code> is touched.</p>

<div class="note">
<b>When this actually matters</b>
<p><code>alwaysRecompile</code> — which <code>--debug</code> sets — makes this unnecessary in development. In production the staleness check compares file modification times, and a deploy method that preserves them (some <code>rsync</code> and tarball-extraction flows do) can leave a stale compiled template being served after an edit that should have replaced it. This is the manual escape hatch for that case.</p>
</div>

<h2>sessions:gc</h2>

[[bash]]
$ ./orbit sessions:gc
Removed 3 expired sessions.

$ ./orbit sessions:gc
Removed 0 expired sessions.
[[/bash]]

<p>Deletes session files past their expiry. <code>FileSessionStore</code> already refuses an expired file on read, so nothing is served incorrectly without this — it exists to stop <code>storage/sessions</code> growing forever on a machine that never runs a scheduler. There is nothing to configure: the directory is the same fixed convention <code>app/bootstrap.php</code> uses, so the command does not boot the application at all.</p>

<p>A deployment with cron or a systemd timer would run it on a schedule; one without either can simply run it by hand occasionally, since a slightly late collection costs disk space, not correctness.</p>

<h2>Adding a command</h2>

<p>The CLI is a plain <code>switch</code> in <code>orbit</code>. Add a case:</p>

[[php]]
<?php
case 'stats':
    /** @var Application $app */
    $app = require $bootstrap;

    printf("%d routes compiled.\n", count($app->router()->routes()));
    exit(0);
[[/php]]

<p>Add it to the usage text too, so <code>orbit help</code> stays honest.</p>

<div class="note">
<b>The CLI is a SAPI boundary</b>
<p><code>orbit</code> is one of the few files allowed to use <code>STDERR</code>, <code>STDOUT</code> and superglobals, because it refuses to run anywhere but the CLI. Code you call <em>from</em> it — anything in <code>src/</code> or <code>app/</code> — must still work on every target, so use <code>StreamLogger::standardError()</code> rather than the <code>STDERR</code> constant there.</p>
</div>

<h2>Exit codes</h2>

<div class="scroller">
<table>
<thead><tr><th>Code</th><th>Meaning</th></tr></thead>
<tbody>
<tr><td><code>0</code></td><td>Success.</td></tr>
<tr><td><code>1</code></td><td>Unknown command, missing dependencies, missing bootstrap, or a configuration error.</td></tr>
</tbody>
</table>
</div>

<p>Configuration problems are reported before anything else runs:</p>

[[bash]]
$ ./orbit routes
Configuration error: Setting "APP_DEBUG" is not a valid boolean.
Accepted values: true/false, 1/0, yes/no, on/off.
[[/bash]]

<h2>Running it from anywhere</h2>

[[bash]]
$ php /srv/app/orbit migrate
[[/bash]]

<p>Paths are resolved from the script's own location and, for settings, against the project root — so a command behaves the same from cron, a deploy script or your shell, regardless of the working directory.</p>
HTML,
];
