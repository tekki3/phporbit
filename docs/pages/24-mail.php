<?php

declare(strict_types=1);

return [
    'slug' => 'mail',
    'title' => 'Sending email',
    'summary' => 'Building messages, sending over SMTP, and the header-injection and TLS rules that are not optional.',
    'body' => <<<'HTML'
[[php]]
<?php
use PhpOrbit\Mail\Mailer;
use PhpOrbit\Mail\Message;

final class WelcomeController implements Handler
{
    public function __construct(private readonly Mailer $mailer)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        $this->mailer->send(
            Message::to('ada@example.test')
                ->subject('Welcome')
                ->text('Thanks for signing up.'),
        );

        return Response::redirect('/');
    }
}
[[/php]]

<p>That is the whole common case. The sender comes from configuration, so a message rarely needs one.</p>

<h2>Configuration</h2>

[[ini]]
# array | smtp
# "array" keeps messages in memory instead of sending them.
MAIL_DRIVER=array

MAIL_HOST=smtp.example.test
# tls (STARTTLS, port 587) | ssl (implicit TLS, port 465) | none
MAIL_ENCRYPTION=tls
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=no-reply@example.test
MAIL_FROM_NAME=phporbit
MAIL_TIMEOUT=10
[[/ini]]

<div class="note">
<b>The default sends nothing</b>
<p><code>array</code> collects messages in memory. A development machine that quietly starts delivering real mail to real people is a worse failure than one that sends none, so choosing <code>smtp</code> is something you write down.</p>
</div>

<h2>Building a message</h2>

[[php]]
<?php
Message::to('Ada Lovelace <ada@example.test>')
    ->addCc('team@example.test')
    ->addBcc('audit@example.test')
    ->replyTo('support@example.test')
    ->subject('Your invoice')
    ->html('<p>Attached.</p>', 'Attached.')   // HTML with a plain-text alternative
    ->attach(Attachment::fromPath('/srv/app/storage/invoice.pdf'))
    ->header('X-Campaign', 'invoices');
[[/php]]

<p>Every call returns a copy, so a half-built message is safe to keep as a template and finish differently per recipient.</p>

<div class="scroller">
<table>
<thead><tr><th>Message</th><th>Structure sent</th></tr></thead>
<tbody>
<tr><td><code>text()</code> only</td><td>A single <code>text/plain</code> part</td></tr>
<tr><td><code>html()</code> only</td><td>A single <code>text/html</code> part</td></tr>
<tr><td>Both</td><td><code>multipart/alternative</code></td></tr>
<tr><td>With attachments</td><td><code>multipart/mixed</code>, nesting the above</td></tr>
</tbody>
</table>
</div>

<p>Pass a plain-text alternative to <code>html()</code> where you can: some clients refuse HTML, and a message offering only an HTML part scores worse with spam filters. <code>Date</code> and <code>Message-ID</code> headers are added for you, for the same reason.</p>

<h2>Two rules that are not configurable</h2>

<h3>Header injection is refused, not stripped</h3>

[[php]]
<?php
new Address('ada@example.test', "Ada\r\nBcc: victim@example.test");
// InvalidArgumentException: A display name may not contain CR, LF or NUL —
//   that is how header injection works.

Message::to('ada@example.test')->subject("Hi\r\nBcc: victim@example.test");
// InvalidArgumentException: Header values may not contain CR, LF or NUL.
[[/php]]

<p>Everything after a newline in a header becomes further headers of the sender's choosing — extra recipients, a forged <code>From</code>, a second body. Subjects and display names are frequently user-supplied, which is what makes this the defect to design against rather than document.</p>

<p>The same rule holds inside the transport: a line consisting of a single <code>.</code> ends the message, so a leading dot on any body line is doubled before it goes out.</p>

<h3>Credentials require an encrypted connection</h3>

[[php]]
<?php
new SmtpSettings('mail.example.test', username: 'ada', password: 'hunter2', encryption: SmtpEncryption::None);
// InvalidArgumentException: Refusing to send credentials over an unencrypted
//   connection. SMTP AUTH base64-encodes the password, which is not encryption
//   — anyone on the path can read it.
[[/php]]

<p>Set <code>MAIL_ALLOW_INSECURE_AUTH=true</code> if the server really is on localhost. TLS certificate verification, separately, has no setting at all: an unverified connection cannot detect the interceptor it exists to stop, and a mail server's certificate is exactly what one would forge to collect the credentials sent moments later.</p>

<h2>Testing</h2>

<p><code>ArrayMailer</code> is the mailer in tests. It validates messages exactly as the real one does, so a test still catches a missing sender or a malformed address.</p>

[[php]]
<?php
$mailer = new ArrayMailer();

$application = Application::boot(static function (Blueprint $app) use ($mailer): void {
    $app->container->singleton(Mailer::class, static fn (): Mailer => $mailer);
    // ...
});

$application->handle(Requests::post('/register', 'email=ada@example.test'));

self::assertSame(1, $mailer->count());
self::assertTrue($mailer->sentTo('ada@example.test'));
self::assertStringContainsString('Welcome', (string) $mailer->last()?->subjectLine);
[[/php]]

<div class="warn">
<b>ArrayMailer is stateful — the one exception</b>
<p>Register it as <code>scoped()</code> rather than <code>singleton()</code> if the application reads back what it collected. As a singleton under a worker it accumulates every message the process has ever sent, and shows one request's mail to the next.</p>
</div>

<h2>How the connection is managed</h2>

<p><code>SmtpMailer</code> opens and closes its connection inside <code>send()</code>. Keeping one open across requests would be faster, and would also mean a worker carrying a stateful socket — possibly mid-transaction after a failure — into the next user's request. Reconnecting costs a round trip and removes a class of bug; that is the trade this framework makes everywhere.</p>

<p>Reads are bounded by <code>MAIL_TIMEOUT</code>. A server that accepts a connection and then says nothing would otherwise hold the process for as long as it liked.</p>

<h2>Every send is persisted</h2>

<p>Both scaffolds wrap the driver-selected mailer in <code>PersistingMailer</code>, so <code>Mailer::class</code> in the container is never the bare driver. Every <code>send()</code> writes one row to <code>mail_log</code> — the full message, and the outcome — after the attempt resolves, then behaves exactly as before: it still throws <code>MailFailed</code> on the same failures, so calling code that already catches it needs no changes.</p>

[[php]]
<?php
// app/bootstrap.php
$mailer = new PersistingMailer(MailerFactory::fromEnvironment($env), new MailLogRepository($database));

$app->container->singleton(Mailer::class, static fn (): Mailer => $mailer);
// Bound under its concrete type too: orbit mail:resend needs resend(), which
// the Mailer interface does not declare.
$app->container->singleton(PersistingMailer::class, static fn (): PersistingMailer => $mailer);
[[/php]]

<div class="scroller">
<table>
<thead><tr><th>Column</th><th>Holds</th></tr></thead>
<tbody>
<tr><td><code>to_addresses</code>, <code>cc_addresses</code>, <code>bcc_addresses</code></td><td>JSON arrays of header-value strings — the same form <code>Address::parse()</code> reads back</td></tr>
<tr><td><code>attachments</code></td><td>JSON: filename, media type and base64-encoded contents — a resend attaches the identical bytes</td></tr>
<tr><td><code>status</code></td><td><code>sent</code> or <code>failed</code> — there is no "pending": sending is synchronous, so the row is written after the attempt resolves</td></tr>
<tr><td><code>error</code></td><td>The server's reply, on failure — the same string a caller would see in <code>MailFailed::getMessage()</code></td></tr>
<tr><td><code>attempts</code></td><td>Starts at 1; a resend increments it in place rather than inserting a new row</td></tr>
</tbody>
</table>
</div>

<p>A validation failure — no recipients, no body, the things <code>assertSendable()</code> catches — is the caller's bug, not a delivery failure, and is deliberately not logged. Only what the <code>Mailer</code> interface's <code>@throws MailFailed</code> actually promises gets recorded.</p>

<h3>Resending</h3>

<p><code>orbit mail:list</code> and <code>orbit mail:resend</code> — see <a href="cli.html">The orbit CLI</a> for every flag.</p>

[[bash]]
$ ./orbit mail:list --status=failed
4     failed  1    bob@example.test                   Reminder                                 2026-01-01T09:14:02+00:00

$ ./orbit mail:resend 4
Resent #4 to bob@example.test.

$ ./orbit mail:resend --failed
2 resent, 0 still failing.
[[/bash]]

<p>Resending is refused for anything that is not currently <code>failed</code> — resending a message already marked <code>sent</code> would deliver it twice with no record that it happened, which defeats the reason the row exists. A resend that fails again updates the same row: the status and error move to the latest attempt, and <code>attempts</code> grows.</p>

<div class="warn">
<b>Still not a queue</b>
<p>Every resend is still a synchronous <code>send()</code> — a slow server slows the <code>orbit mail:resend</code> process the same way it would slow a request. What changed is that a failure is no longer lost: it is a row you can look at and a command you can run, not a retry loop the framework runs on its own.</p>
</div>

<h2>Not built</h2>

<ul>
<li><strong>Automatic retries.</strong> A failed send stays <code>failed</code> until <code>orbit mail:resend</code> is run by hand or from a cron entry you write — the framework does not retry on its own.</li>
<li><strong>DKIM signing.</strong> Sign at the relay — most providers do it for you, and doing it here would mean managing private keys in the application.</li>
<li><strong>Inline images</strong> (<code>cid:</code> references) and <code>multipart/related</code>.</li>
<li><strong>Bounce handling.</strong> A <code>MailFailed</code> means the server refused the message; a delivery failure after that arrives by email or webhook, out of band.</li>
</ul>
HTML,
];
