<?php

declare(strict_types=1);

return [
    'slug' => 'logging',
    'title' => 'Logging',
    'summary' => 'Structured output that lands in the right place on every deployment target.',
    'body' => <<<'HTML'
[[php]]
<?php
use PhpOrbit\Log\Level;
use PhpOrbit\Log\Logger;

final class PublishArticle implements Handler
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        $this->logger->log(Level::Info, 'article published', [
            'id' => $id,
            'author' => $authorId,
        ]);

        return Response::redirect('/articles');
    }
}
[[/php]]

<p>One method, four levels: <code>Debug</code>, <code>Info</code>, <code>Warning</code>, <code>Error</code>.</p>

<h2>Output</h2>

<p>One JSON object per line — greppable by a human, parseable by anything else:</p>

[[text]]
{"time":"2026-08-10T14:22:01+00:00","level":"info","message":"article published","context":{"id":42,"author":7}}
{"time":"2026-08-10T14:22:03+00:00","level":"error","message":"payment declined","context":{"order":118}}
[[/text]]

<p><code>context</code> is omitted entirely when empty, so ordinary lines stay short.</p>
<p>Context supports nested arrays of strings, integers, floats, booleans and <code>null</code>; JSON preserves those types. Objects and resources are refused rather than implicitly serialized. Nesting is limited to 32 levels, including protection against recursive arrays. Invalid UTF-8 bytes are replaced so a malformed request path cannot break JSON logging; other encoding failures are explicit exceptions.</p>
[[php]]
<?php
$logger->log(Level::Info, 'order accepted', [
    'order' => ['id' => 42, 'total' => 19.95, 'paid' => true],
    'items' => ['book', 'pen'],
]);
[[/php]]

<div class="good">
<b>A newline in user input cannot forge an entry</b>
<p>The message is JSON-encoded, so a value containing <code>\n"level":"error"</code> stays one line and one field. Line-oriented logs that interpolate raw strings are trivially forgeable by anyone who can influence a logged value.</p>
</div>

<h2>Where it goes</h2>
<p>The demo, blank scaffold and admin application use the same environment-driven factory. JSON lines remain the only format; no collector or network call runs inside a request.</p>
[[ini]]
LOG_LEVEL=info
LOG_OUTPUT=stderr
LOG_PATH=storage/logs/app.log
LOG_SERVICE=my-app
APP_ENV=production
[[/ini]]
<p><code>LOG_OUTPUT</code> accepts <code>stderr</code> (the default), <code>stdout</code> or <code>file</code>. <code>LOG_PATH</code> is used only for file output, resolves relative to the project root and defaults to <code>storage/logs/app.log</code>. Parent directories are created when needed. Files open in append mode; regular-file writes are locked and flushed so cooperating workers cannot interleave entries. Missing permissions and failed writes surface as errors, not successful log calls. Keep the path outside <code>public/</code>; configure retention, permissions and rotation externally, and restart workers after renaming an open log file.</p>
<p>Prefer <code>stderr</code> under web SAPIs. <code>stdout</code> is intended for CLI/container hosts configured to collect it; do not route logs into an HTTP response or CLI machine-readable output. Hosts may prefix captured stderr lines; file output preserves raw JSON lines for a collector such as Alloy.</p>
[[php]]
<?php
use PhpOrbit\Log\LoggerFactory;

$logger = LoggerFactory::fromEnvironment($env, $root);
[[/php]]
<p>The factory binds <code>service</code> (default <code>phporbit</code>) and <code>environment</code> (default <code>production</code>) in every entry's <code>context</code>. A log call cannot overwrite them. Directly constructing <code>StreamLogger</code> preserves its previous output shape without these fields.</p>

[[php]]
<?php
use PhpOrbit\Log\StreamLogger;

$logger = StreamLogger::standardError(Level::Info);
[[/php]]

<div class="scroller">
<table>
<thead><tr><th>Target</th><th>Where lines land</th></tr></thead>
<tbody>
<tr><td><code>./orbit serve</code></td><td>Your terminal</td></tr>
<tr><td>nginx + PHP-FPM</td><td>The pool's error log</td></tr>
<tr><td>Apache</td><td>The server error log</td></tr>
<tr><td>FrankenPHP</td><td>The server's stderr, and so your container logs</td></tr>
</tbody>
</table>
</div>

<div class="warn">
<b>Never the STDERR constant</b>
<p><code>STDERR</code> is defined only under the CLI SAPI. Referring to it in <code>app/bootstrap.php</code> or anywhere in <code>src/</code> is a fatal error at boot under FPM, Apache and <code>php -S</code> — and the test suite will not catch it, because the suite itself runs under the CLI.</p>
<p><code>StreamLogger::standardError()</code> opens the <code>php://stderr</code> wrapper, which exists everywhere. <code>tests/Unit/PortabilityTest.php</code> fails the build if the constant reappears.</p>
</div>

<p>To log to a file instead:</p>

[[php]]
<?php
$handle = fopen($root . '/storage/logs/app.log', 'ab');
if ($handle === false) {
    throw new \RuntimeException('Cannot open application log.');
}
$logger = new StreamLogger($handle, Level::Info);
[[/php]]

<p>Append mode matters: several workers may hold the same file open.</p>
<p>For reusable immutable context, wrap any logger in <code>new ContextLogger($logger, ['component' =&gt; 'billing'])</code>, or pass a <code>context:</code> array to <code>StreamLogger</code>. Bound fields take precedence over individual calls. Caller-supplied streams remain caller-owned; the factory closes its own handles when the logger is destroyed.</p>

<h2>Levels</h2>

[[php]]
<?php
Level::Debug;     // developing only — noisy by design
Level::Info;      // something happened that you would want in an audit
Level::Warning;   // recovered, but someone should look
Level::Error;     // the request failed

Level::fromName('warning');    // parses configuration
Level::Warning->severity();    // for comparisons
[[/php]]

[[ini]]
LOG_LEVEL=info
[[/ini]]

<p>Entries below the configured minimum are dropped before formatting, so debug logging costs almost nothing when switched off.</p>

<p>A typo is rejected rather than guessed at:</p>

[[text]]
ValueError: Unknown log level "warn". Use one of: debug, info, warning, error.
[[/text]]

<p>Silently falling back to <code>debug</code> would put request detail into production logs; falling back to <code>error</code> would hide warnings someone deliberately asked for.</p>

<h2>Request logging</h2>

[[php]]
<?php
use PhpOrbit\Log\LogRequests;

$app->middleware(new LogRequests($logger));
[[/php]]

[[text]]
{"time":"…","level":"info","message":"request handled","context":{"method":"GET","path":"/articles","status":200,"duration_ms":12.4,"request_id":"…"}}
{"time":"…","level":"info","message":"request handled","context":{"method":"GET","path":"/nope","status":404,"duration_ms":0.8,"request_id":"…"}}
{"time":"…","level":"error","message":"request handled","context":{"method":"POST","path":"/articles","status":500,"duration_ms":31.7,"request_id":"…"}}
[[/text]]

<p>5xx responses log as errors; all other returned responses log as info. A thrown exception produces <code>request failed</code> at error level with its class, not its message or stack trace, and is rethrown for the existing error handler. Register logging <strong>first</strong>, before any middleware resolves <code>Logger</code> from the request scope, so it observes everything below it.</p>
<h3>Request correlation</h3>
<p><code>LogRequests</code> generates a fresh 128-bit random <code>request_id</code> for every invocation, including unmatched routes. Incoming <code>X-Request-ID</code> is deliberately not trusted. It publishes an immutable <code>ContextLogger</code> as <code>Logger</code> in the current request scope, so controllers and scoped services automatically share that ID with the request-completion log. The normally returned response carries the same <code>X-Request-ID</code> header, overriding a handler-supplied value. Exceptions rendered outside the middleware still have the ID in their log, but that outer error response does not carry the header.</p>
<p>Request IDs cannot be overridden through a log call and never mutate the shared logger. Singleton services and code using the original boot logger have only the fixed application context: use the request-scoped logger when correlation is needed. A singleton must never capture a scoped logger. Queries, request bodies, cookies and incoming headers are not automatically logged.</p>

<h2>What not to log</h2>
<p><code>StreamLogger</code> automatically replaces sensitive context values with <code>[REDACTED]</code>, recursively. Field matching ignores case and punctuation: password, password hash/confirmation, secret, token, API key, session ID, private key and recovery-code suffixes are protected, along with authorization headers, cookies, credentials, session, <code>APP_KEY</code> and <code>APP_PREVIOUS_KEYS</code>. For example, <code>db_password</code>, <code>accessToken</code>, <code>Authorization</code> and <code>_token</code> are redacted; a sensitive array is replaced entirely.</p>
<p>This is defense in depth, not a way to make arbitrary data safe. Message text, unrecognized field names and personal information are <strong>not</strong> automatically scrubbed. Never put secrets in messages, URLs, exception text or fields such as <code>value</code>. Custom logger implementations must provide their own protection.</p>

<div class="warn">
<b>Context goes into your log aggregator</b>
<p>Passwords, tokens, session ids, full card numbers and personal data do not belong there. The framework holds this line itself: <code>QueryFailed</code> carries the SQL but never the bound parameters, and configuration errors name the key but never the value.</p>
</div>

[[php]]
<?php
// No
$logger->log(Level::Info, 'login', ['email' => $email, 'password' => $password]);

// Yes
$logger->log(Level::Info, 'login', ['user' => $user->authIdentifier()]);
[[/php]]

<h2>Your own logger</h2>

[[php]]
<?php
use PhpOrbit\Log\Logger;

final class FanOutLogger implements Logger
{
    /** @param list<Logger> $loggers */
    public function __construct(private readonly array $loggers)
    {
    }

    public function log(Level $level, string $message, array $context = []): void
    {
        foreach ($this->loggers as $logger) {
            $logger->log($level, $message, $context);
        }
    }
}
[[/php]]

<p>Register it as the <code>Logger</code> singleton at boot and everything that injects the interface picks it up.</p>

<div class="note">
<b>Only the sink is shared</b>
<p>The boot logger is shared; <code>LogRequests</code> publishes a separate immutable wrapper per request. Never set request fields on a singleton or buffer entries there for a later request. Write as you go, or flush request-owned buffers from <code>$scope-&gt;onClose()</code>.</p>
</div>
HTML,
];
