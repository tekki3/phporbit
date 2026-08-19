<?php

declare(strict_types=1);

return [
    'slug' => 'admin-ui',
    'title' => 'The admin UI',
    'summary' => 'A local web dashboard covering every orbit command that operates on a project — migrations, mail, the code generators, sessions and the template cache — started by orbit ui, wired into nothing else.',
    'body' => <<<'HTML'
[[bash]]
$ orbit ui
Starting the admin UI — migrations, mail, routes, sessions, storage.
phporbit listening on http://127.0.0.1:8081 (production mode) — Ctrl-C to stop
[[/bash]]

<p>A second, self-contained web application for setting up and maintaining a project: how many migrations are pending, what mail failed to send and needs resending, what routes actually compiled, how much is sitting in the session store and the template cache — buttons to act on each, and forms for every <code>orbit make:*</code> generator besides. It reads and writes the same database, the same mail configuration and the same filesystem <code>orbit</code> itself uses; nothing here is a preview.</p>

<h2>Why a second application</h2>

<p>It would be simpler to add these pages to <code>app/routes.php</code>. That is exactly why they are not there. A page that can run migrations, resend mail and wipe the template cache, merged into the real route table, ships with every deployment unless a developer remembers to strip it back out before going to production — and "remembers to remove it" is not a security boundary.</p>

<p>Instead, <code>Admin\AdminApplication::boot()</code> builds an independent <code>Kernel\Application</code> — its own routes, its own middleware, its own <code>TemplateEngine</code> pointed at templates that ship with the framework in <code>src/Admin/templates</code> rather than living in <code>app/templates</code>. It exists only for the lifetime of the process <code>orbit ui</code> starts. Nothing about running it changes what <code>app/routes.php</code> serves, and nothing about deploying normally exposes it.</p>

<div class="warn">
<b>There is no login</b>
<p>What stands in for one: <code>orbit ui</code> binds to <code>127.0.0.1</code> by default — the same as <code>orbit serve</code> — and warns loudly if told to bind anywhere else. Treat it the way you would a database console left open on your own machine: fine on localhost or over an SSH tunnel, never behind a public host or a port forward. Adding real authentication was deliberately left out rather than done halfway; see "Not built" below.</p>
</div>

<h2>What it can do</h2>

<div class="scroller">
<table>
<thead><tr><th>Page</th><th>Shows</th><th>Actions</th></tr></thead>
<tbody>
<tr><td>Overview</td><td>One tile per area below</td><td>—</td></tr>
<tr><td>Migrations</td><td>Every migration file, applied or pending, with its batch</td><td>Run pending, roll back the last batch</td></tr>
<tr><td>Mail</td><td><code>mail_log</code>, filterable by status</td><td>Resend one message, resend every failed message</td></tr>
<tr><td>Routes</td><td>The project's real, compiled route table</td><td>—</td></tr>
<tr><td>Sessions</td><td>Session files on disk</td><td>Remove the expired ones</td></tr>
<tr><td>Storage</td><td>Compiled templates on disk, and their size</td><td>Clear the template cache</td></tr>
<tr><td>Generate</td><td>Five forms: class, controller, form, middleware, migration</td><td>Write the file(s); show what the CLI would have printed</td></tr>
<tr><td>Tools</td><td>The configured mail driver</td><td>Print a new <code>APP_KEY</code>; send one real test message</td></tr>
</tbody>
</table>
</div>

<p>Every one of these is also a CLI command — <code>orbit migrate</code>, <code>orbit mail:list</code> / <code>mail:resend</code>, <code>orbit routes</code>, <code>orbit sessions:gc</code>, <code>orbit storage:clear</code>, <code>orbit make:*</code>, <code>orbit key:generate</code>, <code>orbit mail:test</code> — documented on <a href="cli.html">The orbit CLI</a>. The admin UI does not reimplement any of them: the same <code>Migrator</code>, the same <code>PersistingMailer</code>, the same <code>Console\*Maker</code> classes. A resend triggered from a button behaves identically to one triggered from a terminal, because it is the same call.</p>

<p>The routes page is worth being precise about: it reads <code>app/routes.php</code> directly — compiling a throwaway <code>RouteCollection</code> just to list what is in it — rather than serving those routes itself. The admin app's own router never touches them.</p>

<h2>Generate</h2>

<p>Five pages under <code>/generate</code>, one per <code>orbit make:*</code> command, each a plain form for the same options the CLI flags accept — a name, a lifetime, which fields a form should have. Submitting one calls the exact same <code>ClassMaker</code>, <code>ControllerMaker</code>, <code>FormMaker</code>, <code>MiddlewareMaker</code> or <code>MigrationMaker</code> the CLI uses, on the real project, and shows what it wrote — file paths, and the one or two lines still left to paste into <code>app/routes.php</code> or <code>app/bootstrap.php</code> by hand. Neither the CLI nor this UI edits those files for you; see <a href="cli.html">The orbit CLI</a> for why.</p>

<p>A submission that fails — a reserved word, a name that collides with an existing file, a field name that clashes with a form's own honeypot — redisplays the same form with the message and what was typed, rather than a redirect that would lose both. A successful one clears the name field but keeps the other choices, so generating several related classes with the same lifetime is a few clicks, not a re-selection each time.</p>

<h2>Tools</h2>

<p><code>orbit key:generate</code> and <code>orbit mail:test</code>, as two small forms on one page. Generating a key prints it — the same as the CLI — and writes nothing; copying it into <code>.env</code> is still a deliberate, separate step. Sending a test message goes through the same <code>PersistingMailer</code> every controller uses, so it is one more row on the Mail page and, if it fails, resendable from there once whatever was wrong is fixed.</p>

<h2>State-changing actions are still forms</h2>

<p>Every action — running a migration, resending mail, clearing a cache — is an ordinary <code>&lt;form method="post"&gt;</code> carrying a CSRF token, checked by the same <code>CsrfMiddleware</code> every other phporbit application uses. There is no JavaScript anywhere in this interface, matching the rule the framework's own demo holds itself to: no <code>&lt;script&gt;</code>, no inline <code>style=</code>, no <code>onclick</code>. A destructive-looking action like "roll back last batch" is not gated behind a confirmation dialog, because that would need one — the button's label is the only warning it gets, the same way <code>notes.orbit.php</code>'s delete button works in the demo application.</p>

<h2>Session cookie</h2>

<p>Named <code>orbit_admin_session</code>, not the project's own <code>SESSION_COOKIE</code>. Cookies are scoped by host, not port — <code>orbit serve</code> and <code>orbit ui</code> both default to <code>127.0.0.1</code>, just on different ports — so sharing a cookie name would let the two silently overwrite each other's session while running side by side, which is a completely ordinary way to run both during development.</p>

<h2>Before the first migration</h2>

<p>The Migrations page always works — <code>Migrator</code> creates its own bookkeeping table on first use, on any database, before a single project migration has run. The Mail page depends on <code>mail_log</code> existing and says so plainly rather than raising a raw database error:</p>

[[bash]]
$ orbit ui
# visit /mail before running migrations

  The mail_log table does not exist yet.
  Run pending migrations to enable this page.
[[/bash]]

<h2>Not built</h2>

<ul>
<li><strong>Authentication.</strong> Binding to <code>127.0.0.1</code> is the only protection. A real login would need password storage, session handling for a second identity system and a decision about who is allowed to administer what — all reasonable, none of it built here. Put it behind an SSH tunnel or a VPN if it needs to be reached from anywhere but the machine it runs on.</li>
<li><strong>A confirmation step before destructive actions.</strong> No JavaScript means no dialog; the button label is the whole warning.</li>
<li><strong>Editing data.</strong> Generate writes code — classes, controllers, migrations — the same files <code>orbit make:*</code> writes. It does not edit rows. Changing a note's title or a user's email is still a job for the database directly or a purpose-built admin resource in the application itself.</li>
<li><strong>Concurrency beyond what OrbitServer already has.</strong> Connections are served sequentially, the same as <code>orbit serve</code> — fine for one developer administering one project, not a target for real traffic.</li>
</ul>
HTML,
];
