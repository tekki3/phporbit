<?php

declare(strict_types=1);

return [
    'slug' => 'auth',
    'title' => 'Authentication',
    'summary' => 'Signing users in and out, password hashing, guarding routes, and throttling brute force.',
    'body' => <<<'HTML'
<p>Four pieces: an <code>Identity</code> (your user), a <code>UserProvider</code> (how to find one), a <code>PasswordHasher</code>, and an <code>Authenticator</code> that ties them to the session.</p>

<h2>Your user</h2>

[[php]]
<?php
namespace App\Models;

use PhpOrbit\Auth\Identity;

final class User implements Identity
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        private readonly string $passwordHash,
    ) {
    }

    public function authIdentifier(): string
    {
        return (string) $this->id;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }
}
[[/php]]

<h2>Finding one</h2>

[[php]]
<?php
namespace App\Auth;

use PhpOrbit\Auth\Identity;
use PhpOrbit\Auth\UserProvider;
use PhpOrbit\Database\Connection;

final class DatabaseUserProvider implements UserProvider
{
    public function __construct(private readonly Connection $database)
    {
    }

    public function findByIdentifier(string $identifier): ?Identity
    {
        if (preg_match('/^\d+$/', $identifier) !== 1) {
            return null;
        }

        return $this->hydrate(
            $this->database->query('users')->where('id', '=', (int) $identifier)->first(),
        );
    }

    public function findByEmail(string $email): ?Identity
    {
        return $this->hydrate(
            $this->database->query('users')->where('email', '=', strtolower(trim($email)))->first(),
        );
    }

    public function updatePasswordHash(Identity $user, string $hash): void
    {
        $this->database->query('users')
            ->where('id', '=', (int) $user->authIdentifier())
            ->update(['password_hash' => $hash]);
    }

    /**
     * @param array<string, scalar|null>|null $row
     */
    private function hydrate(?array $row): ?User
    {
        return $row === null ? null : new User(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['display_name'],
            (string) $row['password_hash'],
        );
    }
}
[[/php]]

<h2>Signing in</h2>

[[php]]
<?php
final class LoginAttemptController implements Handler
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly LoginThrottle $throttle,
        private readonly Session $session,
        private readonly TemplateEngine $view,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $email = $request->form('email') ?? '';

        // You build the key. See "Throttling" below for why it combines both.
        $key = mb_strtolower($email) . '|' . ($request->headers->first('X-Forwarded-For') ?? 'local');

        if ($this->throttle->tooManyAttempts($key)) {
            return $this->view->respond('login', [
                'title' => 'Sign in',
                'error' => sprintf('Too many attempts. Try again in %d seconds.', $this->throttle->retryAfter($key)),
            ], Status::TooManyRequests);
        }

        if (!$this->auth->attempt($email, $request->form('password') ?? '')) {
            $this->throttle->record($key);

            // Never say which of the two was wrong.
            return $this->view->respond('login', [
                'title' => 'Sign in',
                'error' => 'Those credentials do not match.',
            ], Status::UnprocessableEntity);
        }

        $this->throttle->clear($key);

        return Response::redirect($this->session->takeFlash(RequireAuthentication::INTENDED_KEY) ?? '/');
    }
}
[[/php]]

<p><code>attempt()</code> does the rest: verifies the password, regenerates the session id, rotates the CSRF token, and rehashes the password if the parameters have changed since it was stored.</p>

<h2>The Authenticator</h2>

[[php]]
<?php
$auth->check();                      // bool — is someone signed in?
$auth->guest();                      // the inverse
$auth->user();                       // ?Identity — re-read per request
$auth->attempt($email, $password);   // bool
$auth->login($user);                 // sign in an Identity you already have
$auth->logout();                     // sign out
[[/php]]

<p>Only the identifier goes into the session; the user is loaded per request. A deactivated or deleted account therefore loses access on its very next request, rather than whenever the session happens to expire.</p>

<h2>Guarding routes</h2>

[[php]]
<?php
use PhpOrbit\Auth\RequireAuthentication;

$routes->withMiddleware([new RequireAuthentication('/login')], static function (RouteCollection $routes): void {
    $routes->get('/account', AccountController::class, 'account');
    $routes->post('/account', UpdateAccountController::class, 'account.update');
});
[[/php]]

<p>Guests are redirected, and where they were heading is flashed so they land there after signing in:</p>

[[php]]
<?php
$intended = $session->takeFlash(RequireAuthentication::INTENDED_KEY) ?? '/';
[[/php]]

<h2>Password hashing</h2>

[[php]]
<?php
$hasher = new PasswordHasher();

$hash = $hasher->hash($plain);              // Argon2id where available, bcrypt otherwise
$hasher->verify($plain, $hash);             // bool
$hasher->needsRehash($hash);                // true once parameters change
[[/php]]

<div class="warn">
<b>Passwords over 72 bytes are rejected</b>
<p>bcrypt silently truncates at 72 bytes, which turns a long passphrase into a much weaker secret without telling anyone. <code>hash()</code> throws rather than accepting input it cannot represent faithfully. Say so in your form's validation.</p>
</div>

<h2>Two timing defences</h2>

<h3>Unknown accounts still cost a verification</h3>

<p>When <code>findByEmail()</code> returns nothing, <code>attempt()</code> runs a decoy verification against a fixed hash. Without it, a failed lookup returns in microseconds and a successful one takes the full hashing time — which is enough to enumerate registered addresses.</p>

<h3>Comparisons are constant-time</h3>

<p>Tokens are compared with <code>hash_equals</code>, and passwords via <code>password_verify</code>. Neither reveals how much of the value was correct.</p>

<h2>Throttling</h2>

[[php]]
<?php
$throttle = new LoginThrottle($database, maxAttempts: 5, windowSeconds: 900);

// The key is yours to build — the throttle stores it hashed and counts it.
$key = mb_strtolower($email) . '|' . ($request->headers->first('X-Forwarded-For') ?? 'local');

$throttle->tooManyAttempts($key);   // bool
$throttle->attempts($key);          // int, within the window
$throttle->retryAfter($key);        // seconds until the window clears
$throttle->record($key);            // after a failure
$throttle->clear($key);             // after a success
$throttle->purge();                 // housekeeping, for cron
[[/php]]

[[ini]]
LOGIN_MAX_ATTEMPTS=5
LOGIN_WINDOW_SECONDS=900
[[/ini]]

<div class="note">
<b>Keyed on email and client address together</b>
<p>Keying on the address alone would let one attacker lock out an office behind a shared IP. Keying on the email alone lets an attacker lock a victim out of their own account deliberately. Both together throttles the actual attack without offering a denial-of-service lever, and the key is stored hashed so the attempts table is not itself a list of accounts to target.</p>
</div>

<h2>Wiring it up</h2>

[[php]]
<?php
Application::boot(static function (Blueprint $app) use ($env): void {
    $app->container->singleton(PasswordHasher::class, static fn (): PasswordHasher => new PasswordHasher());

    $app->container->singleton(
        UserProvider::class,
        static fn (Container $c): UserProvider => new DatabaseUserProvider($c->get(Connection::class)),
    );

    // Scoped: it reads the session, which belongs to this request.
    $app->container->scoped(
        Authenticator::class,
        static fn (RequestScope $scope): Authenticator => new Authenticator(
            $scope->get(Session::class),
            $scope->get(UserProvider::class),
            $scope->get(PasswordHasher::class),
        ),
    );
});
[[/php]]

<h2>In templates</h2>

[[html]]
@if($currentUser !== null)
    <span>{{ $currentUser->displayName }}</span>
    <form method="post" action="/logout">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <button type="submit">Sign out</button>
    </form>
@else
    <a href="/login">Sign in</a>
@endif
[[/html]]

<p>Sign-out is a <code>POST</code>, not a link — a <code>GET</code> logout can be triggered by any image tag on any page.</p>

<h2>Not included</h2>

<p>Registration, password reset and email are yours to write; they need decisions about verification and delivery that a framework should not make for you. The pieces above are what they build on: hash with <code>PasswordHasher</code>, store through your provider, and call <code>login()</code> when the user is confirmed.</p>
HTML,
];
