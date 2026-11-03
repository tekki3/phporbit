<?php

declare(strict_types=1);

return [
    'slug' => 'advanced-auth',
    'title' => 'Advanced authentication',
    'summary' => 'Optional TOTP, recovery codes, reset and magic links, OAuth/OIDC, Google and tenant-allowlisted Entra.',
    'body' => <<<'HTML'
<p>One sign-in pipeline, several primary methods: password, email link, Google or Microsoft Entra ID. An enrolled authenticator remains mandatory for all of them. External accounts are linked explicitly from an existing, password-confirmed account; matching email addresses never create or link accounts.</p>

<h2>Try the complete demo</h2>
<p>For a new application rather than this repository's demo, generate the same security flows plus verified registration and user management with <code>php orbit make:auth</code>.</p>
[[bash]]
composer install
php orbit migrate
php orbit db:seed
php orbit serve
[[/bash]]
<p>Sign in at <code>/login</code> and open <code>/account/security</code>. Confirm your password to enroll an authenticator. Enter the setup key into an authenticator app, or open the <code>otpauth://</code> link on a device with an app installed. Confirm one code, then save the ten recovery codes: they are displayed once, never saved in plaintext. No JavaScript, remote QR service or third-party enrollment request is needed.</p>
<p>The setup key is a secret, not a password. Do not copy it into chat, screenshots or logs. Enrollment expires after ten minutes; an unfinished sign-in expires after five minutes. TOTP uses RFC 6238, SHA-1, six digits and 30-second steps, accepting one step of clock skew in either direction. A database compare-and-swap prevents a time step from being accepted twice; recovery codes are deleted atomically when used.</p>
<p>Disabling MFA requires both your password and an unused authenticator or recovery code. It revokes remaining recovery codes and other sessions. To replace the authenticator or obtain new recovery codes, disable it and enroll again. Password resets and magic links <strong>do not disable MFA</strong>. If both the authenticator and all recovery codes are lost, recovery needs the site operator's independently verified process; there is deliberately no email-only MFA bypass.</p>

<h2>Password reset and magic links</h2>
<p><code>/password/forgot</code> emails a reset link; <code>/magic</code> emails a sign-in link. Both expire after fifteen minutes. Opening a link stores it in the browser's private session and redirects to a clean URL. Only a CSRF-protected POST consumes it: mail scanners and link previews do not reset passwords or sign users in. Use the same browser for the confirmation step. A magic link replaces only the password; MFA is still required.</p>
<p>Reset passwords must be 12–72 bytes. A reset updates the password, revokes all other email links and existing sessions, and leaves MFA enabled. New links are bound to the current password hash and security version. Their 256-bit random values are hashed in the database; purpose, expiration and single use are checked in the same transaction as the action.</p>
[[ini]]
APP_URL=https://app.example.com
MAIL_DRIVER=smtp
MAIL_HOST=smtp.example.com
MAIL_ENCRYPTION=tls
MAIL_PORT=587
MAIL_USERNAME=your-smtp-user
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS=no-reply@example.com
[[/ini]]
<p><code>APP_URL</code> is trusted configuration, never the incoming Host header. HTTPS is required outside loopback development. Keep <code>APP_KEY</code> private and stable; it encrypts TOTP secrets, including pending enrollment. Retired encryption keys are accepted through <code>APP_PREVIOUS_KEYS</code>.</p>
<div class="warn"><b>Authentication mail deliberately bypasses the mail audit and queue</b><p>The normal <code>PersistingMailer</code> stores full mail content in the readable <code>mail_log</code>. Reset and sign-in messages contain usable credentials, so the demo sends them directly through the configured driver, ignoring <code>MAIL_QUEUE</code>, without copying them into that log or admin UI. SMTP failures are logged without message contents, while the browser sees the same generic response. With the default <code>MAIL_DRIVER=array</code>, no email is delivered and a configuration warning is logged. Configure a real SMTP server or a local test mail catcher.</p></div>
<p>Email requests have the same browser response for missing accounts, delivery errors and throttled requests. That response alone does not provide complete timing anonymity: SMTP latency can still vary. Rate limits are account-wide for email requests, second-factor checks and security changes. Changing browsers or spoofing forwarding headers cannot reset them. As with any account-wide limit, malicious requests can temporarily deny a victim access; add trusted edge-level abuse controls in production.</p>

<h2>Google login</h2>
<ol>
<li>Create a Google OAuth web application and configure its consent screen and permitted users.</li>
<li>Register <code>https://app.example.com/auth/google/callback</code> as an exact authorized redirect URI.</li>
<li>Set the client credentials and restart workers.</li>
<li>Sign in locally, open Account security, confirm your password (and an unused MFA code if enabled), and choose <b>Link Google account</b>.</li>
</ol>
[[ini]]
GOOGLE_CLIENT_ID=your-web-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
[[/ini]]
<p>The login button appears only when the provider is configured. Unknown external identities are refused even if their email matches an existing account. Google identities use verified issuer plus subject, not a mutable email address. Unlinking also requires reauthentication and revokes other application sessions.</p>

<h2>Microsoft Entra ID</h2>
<ol>
<li>Create a multi-tenant Entra application registration for organizational directories, and add a Web redirect URI: <code>https://app.example.com/auth/entra/callback</code>.</li>
<li>Create a client secret and copy its <em>value</em>, not its secret ID. Configure the application/client ID and explicitly permitted tenant UUIDs.</li>
<li>Arrange user/admin consent in each permitted tenant, restart workers, then link the account from Account security.</li>
</ol>
[[ini]]
ENTRA_CLIENT_ID=your-application-client-id
ENTRA_CLIENT_SECRET=your-client-secret-value
ENTRA_TENANT_IDS=11111111-1111-1111-1111-111111111111,22222222-2222-2222-2222-222222222222
[[/ini]]
<p>The authorization endpoint uses <code>organizations</code>, but that is <strong>not</strong> an allowlist entry. Only explicit lowercase tenant UUIDs are accepted. The signed <code>tid</code>, issuer and signing-key issuer must agree with the allowlist. Personal Microsoft accounts, arbitrary tenants, automatic email-based linking and automatic provisioning are not supported. Local MFA still applies even when the external provider performed its own MFA.</p>
<p>For local development register the exact loopback callback URL, matching <code>APP_URL</code>, including port and whether you used <code>localhost</code> or <code>127.0.0.1</code>. Real provider credentials and consent are required to test the complete external service; deterministic tests use locally signed JWTs and a fake HTTP transport, not real Google or Microsoft sessions.</p>

<h2>Reusable framework APIs</h2>
<h3>Scaffold a complete authentication module</h3>
[[bash]]
php orbit new my-app
cd my-app
composer install
php orbit make:auth
# Set MAIL_DRIVER=smtp, SMTP credentials and APP_URL in .env.
php orbit migrate
php orbit serve
[[/bash]]
<p>Visit <code>/register</code>. Registration sends a one-use, hashed verification link that expires after thirty minutes. It stores a pending registration, not a sign-in-capable user. A CSRF-protected confirmation POST creates the account; a mail scanner's GET does not. Re-submit the registration form to request another email. Expired pending rows are reclaimed on new requests. Pending and verified passwords are hashed with <code>PasswordHasher</code>. Registrations, verification mail and reset links never enter the readable mail audit.</p>
<p>The default <code>MAIL_DRIVER=array</code> cannot deliver verification email, so registration shows an explicit configuration error rather than granting an unverified account access. SMTP delivery failures are logged without message contents, and the browser receives the same generic response as duplicate addresses and account-wide throttles. As with other email flows, network timing can still vary. Production deployments should add trusted edge-level abuse limits.</p>
<p>After registering and verifying your own email, appoint the first administrator from a trusted terminal:</p>
[[bash]]
php orbit auth:admin you@example.com
[[/bash]]
<p>Everyone registers as a normal member. No automatic first-user administrator, default credentials or unauthenticated elevation route exists. Administrators can list users in pages of 25, block/unblock and grant/remove administrator access. Each change requires the acting administrator's password and an unused MFA/recovery code if enabled. The actor is rechecked inside a transaction, management changes are serialized with a database lock row, and changes revoke the affected user's sessions and pending challenges. Administrators cannot block or demote themselves, and the last active administrator cannot be removed. Unblocking does not revive revoked sessions.</p>
<p>Members can change their own display name at <code>/account</code>; email is deliberately read-only so an edit cannot bypass verification. Account security lives at <code>/account/security</code>, user management at <code>/account/users</code>. Registration, login, reset and magic-link pages are linked from the generated account layout. MFA and explicitly linked OAuth/OIDC accounts reuse the existing framework services.</p>
<div class="scroller"><table>
<thead><tr><th>Generated path</th><th>Purpose</th></tr></thead>
<tbody>
<tr><td><code>app/auth.php</code></td><td>Boot-time service bindings and authentication route loading.</td></tr>
<tr><td><code>app/auth-routes.php</code></td><td>Public authentication routes and guarded account/admin routes.</td></tr>
<tr><td><code>app/src/AuthKit/</code></td><td>Editable user/provider, registration, guards, helpers and single-action controllers.</td></tr>
<tr><td><code>app/templates/auth-kit/</code></td><td>Independent account layout and escaped forms; no JavaScript or inline CSS.</td></tr>
<tr><td><code>public/assets/css/auth.css</code></td><td>Self-contained copy of the demo stylesheet; does not replace your existing CSS.</td></tr>
<tr><td><code>database/migrations/*_create_auth_kit.php</code></td><td>Reversible schema for verified users, pending registrations, management lock, throttling and shared security tables.</td></tr>
<tr><td><code>tests/AuthKitTest.php</code></td><td>Generated round-trip tests for verification, MFA, profiles, blocking and administrator permissions.</td></tr>
</tbody></table></div>
<p>The command checks every output path before writing and never overwrites existing code, including on a second run. There is deliberately no <code>--force</code>; edit generated files normally. It does not change your <code>.env</code>, application layout or existing routes. New blank projects have an optional boot hook for <code>app/auth.php</code>. In an older application, add the following <em>inside</em> its boot callback after registering <code>Connection</code>, <code>Environment</code>, <code>Logger</code>, <code>TemplateEngine</code> and encryption services, before the container freezes:</p>
[[php]]
<?php
$configureAuth = require $root . '/app/auth.php';
if (!$configureAuth instanceof \Closure) {
    throw new \RuntimeException('app/auth.php must return a boot callback.');
}
$configureAuth($app, $env, $root);
[[/php]]
<p>Keep <code>SessionMiddleware</code> before <code>CsrfMiddleware</code>. Generate an <code>APP_KEY</code> with <code>orbit key:generate</code> if your existing application has none. Add links to <code>/login</code>, <code>/register</code> and <code>/account</code> in your own site's layout as appropriate. Restart long-lived workers after generation or configuration changes. Neither generation nor administrator promotion boots or migrates the application implicitly.</p>
<p>This generator is intended for blank or not-yet-authenticated applications. It refuses the existing demo provider rather than installing conflicting logins. In a customized existing application, first check for conflicting URLs, <code>UserProvider</code>/<code>Authenticator</code> bindings, <code>auth_*</code> tables and migration ordering. Integrate with existing authentication manually instead of registering two systems. The module uses a separate <code>auth_users</code> table, and does not migrate your existing users. Run <code>composer test</code> and <code>composer stan</code> after installation; the generated tests exercise one boot handling multiple visitors.</p>

<h3>Assemble your own application</h3>
<p>The framework classes are optional; blank scaffolds do not suddenly gain accounts or authentication routes. A demo scaffold includes the complete application. In an existing application, provide <code>Identity</code>/<code>UserProvider</code>, install the ordinary users and <code>auth_attempts</code> tables, and add a migration returning the reusable security schema:</p>
[[php]]
<?php
declare(strict_types=1);

return new \PhpOrbit\Auth\SecurityMigration();
[[/php]]
<p><code>SecurityMigration</code> creates <code>auth_security</code>, <code>auth_recovery_codes</code>, <code>auth_email_tokens</code> and <code>auth_external_accounts</code>, with reversible, portable DDL for SQLite, MySQL, PostgreSQL and SQL Server. User identifiers must fit 191 bytes. External subjects and issuers have the same limit. The existing <code>auth_attempts</code> schema has an auto-increment <code>id</code>, <code>attempt_key VARCHAR(191)</code>, <code>attempted_at BIGINT</code> and an index on the last two columns; the demo's migration 0003 supplies it.</p>
[[php]]
<?php
use PhpOrbit\Auth\{Authenticator, EmailTokens, Identity, LoginFlow, Mfa, SecurityState};
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Session\Session;

$state = new SecurityState($database);
$mfa = new Mfa($database, $state, $encrypter);
$tokens = new EmailTokens($database, $users, $state);

// Immutable services are singletons; Authenticator/LoginFlow are scoped.
$app->container->singleton(SecurityState::class, fn (): SecurityState => $state);
$app->container->singleton(Mfa::class, fn (): Mfa => $mfa);
$app->container->scoped(Authenticator::class, fn (RequestScope $scope): Authenticator =>
    new Authenticator($scope->get(Session::class), $users, $hasher,
        fn (Identity $user): string =>
            hash('sha256', $user->passwordHash() . ':' . $state->version($user->authIdentifier()))));

// In the request handler, after checking the primary factor:
$user = $auth->validateCredentials($email, $password);
if ($user !== null) {
    $flow->begin($user); // Logs in, or establishes a pending MFA challenge.
}
$flow->pending();
$flow->complete($code); // Only works for an existing pending challenge.
[[/php]]
<p>Also bind <code>UserProvider</code>, <code>PasswordHasher</code>, <code>Connection</code> and <code>LoginThrottle</code>; register <code>SessionMiddleware</code> before <code>CsrfMiddleware</code>, and guard account-management routes with <code>RequireAuthentication</code>. <code>LoginFlow</code> and <code>OidcClient</code> are autowired per request. A lazy <code>Closure(): Encrypter</code> may be passed to <code>Mfa</code> so password-only users do not require encryption at boot. Never register the session, authenticator, login flow or OIDC client as a singleton.</p>
[[php]]
<?php
$secret = \PhpOrbit\Auth\Totp::secret();
$setupUri = \PhpOrbit\Auth\Totp::uri($secret, $accountName, 'My application');
$recoveryCodes = $mfa->enable($userId, $secret, $code); // Show once.
$mfa->enabled($userId);
$mfa->verify($userId, $code); // Consumes the TOTP step or recovery code.
$mfa->disable($userId, $unusedCode);

$token = $tokens->issue($user, 'password-reset', lifetimeSeconds: 900);
$reset->reset($token, $newPassword); // PasswordReset; transaction + revocation.
$token = $tokens->issue($user, 'magic-login');
$tokens->consume($token, 'magic-login', static function (Identity $identity) use (&$primary): void {
    $primary = $identity;
});
// After the database transaction has committed:
$flow->begin($primary);
[[/php]]
<p>Authenticate and authorize enrollment, disabling and external-account changes <strong>before</strong> calling these low-level APIs. <code>Mfa::verify()</code> is intentionally consuming; do not call it once to preview and again to log in. <code>EmailTokens::consume()</code> wraps its callback in a transaction: use the same connection for the action, avoid nested transactions, and do not perform irreversible external effects inside it.</p>
<p><code>SecurityState::revoke($userId)</code> invalidates stamped sessions and pending challenges on their next request. Keep <code>UserProvider::updatePasswordHash()</code> on the same transactional database connection when using <code>PasswordReset</code>. <code>Authenticator::login()</code> remains a trusted low-level operation: bypassing <code>LoginFlow</code> in your own controller bypasses your MFA policy.</p>
<p>Schedule <code>EmailTokens::purge()</code> and <code>LoginThrottle::purge()</code> to reclaim expired rows. Expiration is enforced on every read whether or not housekeeping runs; there is no internal scheduler.</p>

<h2>Other OAuth 2.0/OpenID Connect providers</h2>
<p><code>OidcProvider</code> accepts a name, client ID/secret, callback URI, trusted HTTPS authorization/token/JWKS endpoints and exact allowed issuers. Register it in <code>OidcProviders</code> and bind <code>OidcHttp</code> to <code>CurlOidcHttp</code> (requires <code>ext-curl</code>), or supply a test transport. Provider responses never select URLs. The client implements the OAuth 2.0 authorization-code flow with PKCE S256, browser-bound one-use state, nonce and five-minute authorization state. ID tokens must have valid RS256 signatures, issuer, subject, audience/authorized party, nonce, issued-at and expiration claims. JWT and JWKS verification uses <code>firebase/php-jwt</code>; no token parser or asymmetric signature scheme is handwritten.</p>
<p>Use <code>OidcClient::start()</code> and <code>finish()</code> to obtain a verified <code>ExternalIdentity</code>. <code>ExternalAccounts::user()</code> resolves only previously linked identities. For linking, pass the current user ID and a fresh security stamp to <code>start()</code>; after <code>finish()</code>, verify that the same signed-in user and stamp remain before calling <code>ExternalAccounts::link()</code>. The demo controllers show the complete pattern. No access or refresh tokens are retained, and provider email claims are not trusted as ownership proof. This is authentication over OAuth/OIDC, not an OAuth authorization server or a generic non-OIDC social-profile client.</p>
<p>HTTPS peer verification is always enabled, redirects are disabled, requests have time and response-size limits, and signing keys are fetched only from configured endpoints. Provider configuration is read at boot, so restart long-lived workers after changing it. Keep application clocks synchronized. Set authentication responses to <code>Cache-Control: no-store</code>; do not log URL query strings, tokens, setup secrets or recovery codes. Configure any upstream proxy/access logger to redact query strings on auth callback and email-link routes too.</p>
HTML,
];
