<?php

declare(strict_types=1);

return [
    'slug' => 'configuration',
    'title' => 'Configuration',
    'summary' => 'The .env file, typed access, and why the real environment always wins.',
    'body' => <<<'HTML'
<p>Configuration is read <strong>once at boot</strong> into an immutable <code>Environment</code>, registered as a singleton. A worker touches the filesystem once per process, and settings cannot drift mid-process — every request sees the same values.</p>

<h2>The .env file</h2>

[[ini]]
# Copy .env.example to .env. The file is git-ignored; the example is not.

APP_DEBUG=false
APP_URL=http://localhost:8080
LOG_LEVEL=info

DB_DATABASE=storage/app.sqlite

SESSION_LIFETIME=7200
SESSION_COOKIE=orbit_session

UPLOAD_MAX_BYTES=1048576
LOGIN_MAX_ATTEMPTS=5
LOGIN_WINDOW_SECONDS=900

TRUSTED_PROXIES=
[[/ini]]

<div class="warn">
<b>The real environment wins</b>
<p>Values already present in the process environment override the file. The <code>.env</code> is a development convenience and a source of defaults; in production the values injected by systemd, Docker or Kubernetes are the ones that must apply, and <strong>a stale <code>.env</code> left on a server must never override them</strong>.</p>
<p><code>./orbit serve --debug</code> works by setting <code>APP_DEBUG</code> in the process environment — the same rule, not an exception to it.</p>
</div>

<h2>Reading settings</h2>

<p>There is no <code>get(): mixed</code>. Everything in a <code>.env</code> is a string, and the conversion has to happen somewhere; doing it here means a bad value fails at boot with a readable message instead of behaving strangely later.</p>

[[php]]
<?php
use PhpOrbit\Config\Environment;

final class ReportController implements Handler
{
    public function __construct(private readonly Environment $config)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        $perPage = $this->config->int('REPORT_PAGE_SIZE', 50);
        $verbose = $this->config->bool('REPORT_VERBOSE', false);
        $title   = $this->config->string('REPORT_TITLE', 'Monthly report');

        // ...
    }
}
[[/php]]

<div class="scroller">
<table>
<thead><tr><th>Method</th><th>Behaviour</th></tr></thead>
<tbody>
<tr><td><code>string($key, $default = null)</code></td><td>Throws if absent and no default given.</td></tr>
<tr><td><code>required($key)</code></td><td>Throws if absent <em>or blank</em>. Use for secrets.</td></tr>
<tr><td><code>int($key, $default = null)</code></td><td>Throws unless the value is digits, optionally signed.</td></tr>
<tr><td><code>bool($key, $default = null)</code></td><td>Accepts true/false, 1/0, yes/no, on/off. Anything else throws.</td></tr>
<tr><td><code>strings($key, $default = [])</code></td><td>Comma-separated list, blanks dropped.</td></tr>
<tr><td><code>path($key, $root, $default = null)</code></td><td>Resolves relative values against the project root.</td></tr>
<tr><td><code>raw($key)</code></td><td>The literal string, or <code>null</code>. Blank and absent are distinguishable.</td></tr>
<tr><td><code>has($key)</code>, <code>keys()</code></td><td>Presence, and the key names (never values).</td></tr>
</tbody>
</table>
</div>

<h3>required() versus string()</h3>

[[php]]
<?php
// APP_KEY= (present, but blank)

$config->string('APP_KEY');    // returns '' — present, technically
$config->required('APP_KEY');  // throws: "present but empty"
[[/php]]

<p>For a secret those are the same problem, which is why <code>required()</code> exists.</p>

<h3>path() and the working directory</h3>

[[php]]
<?php
// DB_DATABASE=storage/app.sqlite
$config->path('DB_DATABASE', $root);   // /srv/app/storage/app.sqlite

// Absolute values and driver-specific ones pass through untouched.
// DB_DATABASE=/var/lib/app.sqlite  →  /var/lib/app.sqlite
// DB_DATABASE=:memory:             →  :memory:
[[/php]]

<p>Without this, whether a relative path works depends on the directory the process happened to start in — which differs between the CLI, a web server and cron.</p>

<h2>Typos fail at boot</h2>

[[bash]]
$ ./orbit routes
Configuration error: Setting "APP_DEBUG" is not a valid boolean.
Accepted values: true/false, 1/0, yes/no, on/off.
[[/bash]]

<p>A typo'd <code>APP_DEBUG=treu</code> that silently became <code>false</code> would hide errors; one that became <code>true</code> would put stack traces in production. Neither is acceptable, so it refuses to start.</p>

<h2>File syntax</h2>

[[ini]]
PLAIN=no quotes needed
TRAILING=value # a comment needs a space before the hash
HASH=pass#word                 # no space, so this stays part of the value

QUOTED="escapes \n \t and ${OTHER_KEY} expansion"
LITERAL='no escapes, no expansion, $ and \ are ordinary'

MULTILINE="-----BEGIN KEY-----
a quoted value may span lines
-----END KEY-----"

export ALSO_FINE=1
[[/ini]]

<ul>
<li><strong>Double quotes</strong> support <code>\n \r \t \\ \" \$</code> and <code>${VAR}</code> expansion.</li>
<li><strong>Single quotes</strong> are entirely literal — the right choice for a password containing backslashes or dollars.</li>
<li><code>${VAR}</code> reads keys defined earlier in the file, or the real environment. An undefined reference is an <strong>error</strong>, not an empty string: silently expanding a password to nothing fails far from its cause.</li>
<li>A bare <code>$</code> is left alone; only the braced form is a reference.</li>
</ul>

<h2>Two secrecy rules</h2>

<div class="good">
<b>Errors never quote values</b>
<p>Parse failures name the key and the line number only. Exception messages travel into logs, error pages and bug reports.</p>
</div>

<div class="good">
<b>Debug output is redacted</b>
<p><code>Environment::__debugInfo()</code> returns key names and <code>&lt;redacted&gt;</code>. A <code>var_dump</code> in a stack trace cannot spill every credential the object holds.</p>
</div>

[[php]]
<?php
$config = Environment::fromArray(['DB_PASSWORD' => 'hunter2']);

print_r($config);
// PhpOrbit\Config\Environment Object
// (
//     [keys] => DB_PASSWORD
//     [values] => <redacted>
// )
[[/php]]

<h2>Adding your own settings</h2>

<p>Read them in <code>app/bootstrap.php</code> and pass concrete values into your services. Services should take what they need, not the whole <code>Environment</code>:</p>

[[php]]
<?php
$app->container->singleton(
    Mailer::class,
    static fn (): Mailer => new Mailer(
        host: $env->required('MAIL_HOST'),
        port: $env->int('MAIL_PORT', 587),
        timeout: $env->int('MAIL_TIMEOUT', 10),
    ),
);
[[/php]]

<p>That way a missing setting fails at boot, and the service is testable without an environment at all.</p>

<h2>Where .env lives</h2>

<p>Beside <code>composer.json</code>, one level above <code>public/</code>, so it is unreachable even if a rewrite rule is misconfigured. <code>ServeStaticFiles</code> also refuses dotfiles outright — two independent protections.</p>
HTML,
];
