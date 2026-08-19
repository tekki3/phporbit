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

<div class="good">
<b>A newline in user input cannot forge an entry</b>
<p>The message is JSON-encoded, so a value containing <code>\n"level":"error"</code> stays one line and one field. Line-oriented logs that interpolate raw strings are trivially forgeable by anyone who can influence a logged value.</p>
</div>

<h2>Where it goes</h2>

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
$logger = new StreamLogger($handle, Level::Info);
[[/php]]

<p>Append mode matters: several workers may hold the same file open.</p>

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
{"time":"…","level":"info","message":"request","context":{"method":"GET","path":"/articles","status":200,"ms":12.4}}
{"time":"…","level":"warning","message":"request","context":{"method":"GET","path":"/nope","status":404,"ms":0.8}}
{"time":"…","level":"error","message":"request","context":{"method":"POST","path":"/articles","status":500,"ms":31.7}}
[[/text]]

<p>The level follows the status: 5xx logs as an error, 4xx as a warning, everything else as info. Register it <strong>first</strong>, so it observes the true status of everything below it — including responses produced by other middleware.</p>

<h2>What not to log</h2>

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
<b>Loggers are shared</b>
<p>A logger is a boot singleton, used by every request the worker serves. Do not buffer entries on the instance intending to flush them at the end of a request — that buffer would span requests. Write as you go, or flush from <code>$scope-&gt;onClose()</code>.</p>
</div>
HTML,
];
