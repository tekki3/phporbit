<?php

declare(strict_types=1);

return [
    'slug' => 'sessions',
    'title' => 'Sessions',
    'summary' => 'Reading and writing session data, flash messages, regeneration, and why PHP\'s own sessions are not used.',
    'body' => <<<'HTML'
<div class="note">
<b>Not PHP's sessions</b>
<p><code>session_start()</code> and <code>$_SESSION</code> are process-global. Under a worker that means one visitor's data is still in memory when the next request arrives — a session leak with no symptoms until it is a breach. phporbit implements its own, per-request and disposable. The portability test fails the build if the session extension is used anywhere.</p>
</div>

<h2>Using it</h2>

<p>Inject <code>Session</code>; <code>SessionMiddleware</code> publishes it:</p>

[[php]]
<?php
use PhpOrbit\Session\Session;

final class PreferencesController implements Handler
{
    public function __construct(private readonly Session $session)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        $this->session->set('theme', 'dark');

        return Response::redirect('/');
    }
}
[[/php]]

<h2>Reading and writing</h2>

[[php]]
<?php
$session->set('theme', 'dark');        // string|int|float|bool
$session->get('theme');                // ?string
$session->getInt('items');             // ?int
$session->getBool('onboarded');        // bool
$session->has('theme');                // bool
$session->remove('theme');
$session->all();                       // array<string, scalar>
[[/php]]

<div class="warn">
<b>Scalars only</b>
<p>Storing objects would mean serialising user-influenced data and unserialising it later, which is a well-known route to remote code execution. Store an id and reload the object.</p>
</div>

<h2>Flash messages</h2>

<p>For the redirect-after-write pattern — write, flash, redirect, show once:</p>

[[php]]
<?php
$this->session->flash('notice', 'Article published.');

return Response::redirect('/articles');
[[/php]]

[[php]]
<?php
// On the next request — reading also removes it
$notice = $this->session->takeFlash('notice');
[[/php]]

[[html]]
@if($notice !== null)
    <p class="notice">{{ $notice }}</p>
@endif
[[/html]]

<h2>Regeneration</h2>

<p>Issue a new session id whenever the privilege level changes:</p>

[[php]]
<?php
$session->regenerate();   // keeps the data, returns the old id
[[/php]]

<p>Without this, an attacker who fixes a victim's session id before login still holds a valid id afterwards. <code>Authenticator::login()</code> does it for you, so calling that instead of writing the user id yourself is the safer habit.</p>

<p>The old session file is deleted, so the previous id stops working immediately.</p>

<h2>Destroying</h2>

[[php]]
<?php
$session->destroy();   // empties it and removes the stored file
[[/php]]

<p>The response also carries an expired cookie, so the browser drops its copy.</p>

<h2>Configuration</h2>

[[php]]
<?php
use PhpOrbit\Session\FileSessionStore;
use PhpOrbit\Session\SessionMiddleware;

$app->middleware(new SessionMiddleware(
    new FileSessionStore($storage . '/sessions'),
    cookieName: 'orbit_session',
    lifetimeSeconds: 7200,
    sameSite: SameSite::Lax,
));
[[/php]]

[[ini]]
SESSION_LIFETIME=7200
SESSION_COOKIE=orbit_session
[[/ini]]

<div class="note">
<b>Order matters</b>
<p><code>SessionMiddleware</code> must run before <code>CsrfMiddleware</code>, which reads the token it holds. Register it first.</p>
</div>

<h2>What the framework does for you</h2>

<h3>No session file for anonymous visitors</h3>

<p>A session is only written once something is actually stored. Someone who reads one page and leaves does not leave a file behind.</p>

<h3>Unknown ids are never adopted</h3>

<p>A cookie naming a session that does not exist gets a <em>new</em> session, not that id. Honouring it is precisely what makes session fixation possible.</p>

<h3>Ids never reach the filesystem unvalidated</h3>

<p>Ids are 256 bits of hex and are matched against a strict pattern before any file operation. A crafted cookie cannot steer a read or a write out of the session directory.</p>

<h3>Writes are atomic</h3>

<p>Data is written to a temporary file and renamed, which is atomic on POSIX filesystems. A reader sees either the old session or the new one, never a half-written file — which matters under the built-in server and FrankenPHP, where requests can touch one session in quick succession. Files are stored at mode <code>0600</code>.</p>

<h3>Corrupt files fail soft</h3>

<p>An unreadable or malformed session file is treated as no session at all. The visitor gets a fresh one rather than an error page.</p>

<h2>Expiry</h2>

[[php]]
<?php
$store = new FileSessionStore($storage . '/sessions');
$removed = $store->collectGarbage();   // deletes expired files, returns the count
[[/php]]

<p>Expiry is stored in the file, so an expired session is refused on read even if the file is still there. Run <code>collectGarbage()</code> from cron for housekeeping.</p>

<h2>Another store</h2>

[[php]]
<?php
use PhpOrbit\Session\SessionStore;

final class RedisSessionStore implements SessionStore
{
    public function read(string $id): ?array { /* … */ }

    public function write(string $id, array $data, int $lifetimeSeconds): void { /* … */ }

    public function destroy(string $id): void { /* … */ }

    public function collectGarbage(): int { return 0; }   // Redis expires keys itself
}
[[/php]]

<p>Worth doing once you run more than one application server, since file sessions are local to a machine.</p>

<h2>Worker safety</h2>

[[php]]
<?php
public function test_sessions_do_not_leak_between_visitors(): void
{
    $app = $this->bootApplication();

    $first = $app->handle(Requests::post('/preferences', 'theme=dark'));
    $cookie = $this->sessionCookieFrom($first);

    // A different visitor, same process.
    $second = $app->handle(Requests::get('/preferences'));

    self::assertStringNotContainsString('dark', $second->body);
}
[[/php]]

<p>Tests like this live in <code>tests/Worker/</code> and boot once, handling several requests — the only way this class of bug is visible. <a href="testing.html">More &rarr;</a></p>
HTML,
];
