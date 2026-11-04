<?php

declare(strict_types=1);

return [
    'slug' => 'api',
    'title' => 'APIs',
    'summary' => 'JWT and personal Bearer tokens, versioned routes, shared rate limits and local Swagger UI.',
    'body' => <<<'HTML'
<p>First-class APIs use the same request pipeline on workers and per-request SAPIs. The demo and <code>make:auth</code> include JSON login, scoped profile access, token revocation, browser-based personal-token management and interactive Swagger UI. Browser sessions never authenticate Bearer routes.</p>

<h2>Start here</h2>
<p>For a new endpoint, use the CLI. It writes the JSON controller and an editable declaration, then loads that declaration automatically at boot. No route registration needs to be pasted.</p>
[[bash]]
# Protected endpoint: GET /api/v1/products
php orbit make:api Products

# Public endpoint: GET /api/v1/ping
php orbit make:api Ping --public

# POST /api/v2/create-product
php orbit make:api CreateProduct --method=POST --version=2

php orbit migrate
# Restart your server, then visit /api/docs.
[[/bash]]
<p><code>make:api Products</code> creates <code>app/src/Controllers/Api/V1/ProductsController.php</code> and <code>app/api/v1/products.php</code>. Edit the controller to return your data and the declaration to describe the operation in OpenAPI. The initial response is <code>{"message":"It works"}</code>. Names use StudlyCase and may be nested, such as <code>Admin/Report</code>. Supported methods are GET, POST, PUT, PATCH and DELETE; versions range from 1 to 999. Existing files are never overwritten.</p>
<p>Bearer protection is the default. In a blank project, run <code>php orbit make:auth</code> once to install account/token authentication, or supply your own <code>Tokens</code> service and dependencies. A public endpoint needs no account setup. All generated endpoints default to sixty requests per minute, per user and operation (a shared anonymous bucket for public endpoints). The command installs the API migration and local Swagger assets when needed; run migrations before making requests. Adding authentication later reuses the existing API schema.</p>
<p><code>Blueprint::loadRoutes()</code> automatically discovers adjacent <code>api/vN/*.php</code> declarations when loading <code>app/routes.php</code>. Each file returns a closure taking <code>VersionedRoutes</code>. Files are loaded once at boot, not during requests. The CLI route list, admin route list and the demo/generated-auth OpenAPI document include these endpoints too. Projects using a custom routing bootstrap can explicitly call <code>RouteFiles::register($routes, $root . '/app/api')</code>. Custom documentation handlers can merge declarations using <code>RouteFiles::document($directory, $existingDocument)</code>.</p>
[[bash]]
# In this repository's demo:
composer install
php orbit migrate
php orbit db:seed
php orbit serve

# Or, inside a new blank project:
php orbit make:auth
# Configure SMTP and APP_URL.
php orbit migrate
php orbit serve
# Register and verify your account on the running application.
[[/bash]]
<p>The demo uses the existing seeded account. Generated authentication uses your verified account. Visit <code>/api/docs</code> for Swagger UI, <code>/api/openapi.json</code> for the OpenAPI 3.0.3 document, and <code>/account/tokens</code> while signed in to create or revoke personal tokens. Run migrations before calling token or rate-limit endpoints. Restart workers after application or configuration changes.</p>
[[bash]]
curl -X POST http://127.0.0.1:8080/api/v1/token \
  -H 'Content-Type: application/json' \
  -d '{"email":"you@example.test","password":"your-password","code":"123456"}'

curl http://127.0.0.1:8080/api/v1/me \
  -H 'Authorization: Bearer YOUR_TOKEN'

curl -X DELETE http://127.0.0.1:8080/api/v1/token \
  -H 'Authorization: Bearer YOUR_TOKEN'
[[/bash]]
<p>Omit <code>code</code> only when MFA is not enabled. The login endpoint returns <code>access_token</code>, <code>token_type: "Bearer"</code> and <code>expires_in: 900</code>. Incorrect credentials and missing, invalid or replayed MFA codes share a generic 401. API login creates no browser login; reset/magic/OIDC browser flows are not silently exchanged for API tokens.</p>

<h2>Tokens and revocation</h2>
<div class="scroller"><table>
<thead><tr><th>Kind</th><th>Lifetime</th><th>Use</th></tr></thead>
<tbody>
<tr><td>JWT access token</td><td>15 minutes by default, at most one hour</td><td>Short-lived API sessions issued after password and optional MFA.</td></tr>
<tr><td>Personal Bearer token</td><td>30 days by default, at most 90 days</td><td>Named scripts and integrations; random 256-bit secret, shown once.</td></tr>
</tbody></table></div>
<p>Both kinds have a SHA-256 token fingerprint in <code>api_tokens</code>; no usable token is stored. JWTs are deliberately not stateless: every request checks expiry, the current user provider, the token record, password hash and security version. Deleting a user, blocking it through its provider, resetting its password or revoking account security invalidates tokens. A token can also be individually revoked immediately. Revocation does not log the browser out or invalidate other tokens.</p>
<p>JWT signing is fixed to HS256 using a purpose-derived key from <code>APP_KEY</code>, distinct from encryption and other signing keys. Issuer, audience, issued-at, expiry, subject, token ID and signature are verified. JWT payloads are readable, not encrypted. Signing-key rotation invalidates existing JWTs: previous application keys are not accepted for JWT verification. Personal tokens are independent of that signing key but remain bound to account security.</p>
[[ini]]
APP_URL=https://app.example.com
# Defaults to APP_URL; keep it stable across workers.
API_JWT_ISSUER=https://app.example.com
API_JWT_AUDIENCE=phporbit-api
[[/ini]]
<p>Use HTTPS in production and protect <code>APP_KEY</code>. Never put tokens in query strings, cookies, logs, emails or public caches. A compromised token has its scopes until expiry or revocation; MFA is checked at issuance, not prompted on every Bearer request. The demo grants only <code>profile:read</code>. There is no wildcard scope, implicit administrator access, refresh token or OAuth2 authorization server.</p>

<h2>Personal-token management</h2>
<p><code>/account/tokens</code> requires a browser login. Creating or revoking tokens additionally requires CSRF protection, the current password and an unused MFA/recovery code when enrolled. The full token appears only in the creation response, under <code>Cache-Control: no-store</code>; it is never flashed into a session. Store it securely before leaving the page. Unexpired-token metadata is listed, capped at 100 entries; records invalidated by account security changes can remain listed until expiry or individual revocation. Expired records are reclaimed during token issuance. Revoke unused records before creating more.</p>

<h2>Reusable framework services</h2>
<p>Add a migration returning <code>new PhpOrbit\Api\ApiMigration()</code>. It creates token, rate-window and rate-lock tables; <code>down()</code> is reversible. Authentication also needs the existing <code>SecurityMigration</code> schema and your <code>UserProvider</code>. The generated auth migration already includes both; do not install them twice.</p>
[[php]]
<?php
use PhpOrbit\Api\Tokens;
use PhpOrbit\Api\RateLimiter;
use PhpOrbit\Crypto\Key;

$tokens = new Tokens(
    $database,
    $users,
    $securityState,
    static fn (): Key => Key::fromString($env->required('APP_KEY')),
    $env->string('API_JWT_ISSUER', $env->string('APP_URL')),
    $env->string('API_JWT_AUDIENCE', 'phporbit-api'),
);
$limiter = new RateLimiter($database);
// Register both as container singletons at boot.

// Only after checking the user's authentication and MFA policy:
$issued = $tokens->personal($user, 'Build server', ['profile:read'], 86400);
$access = $tokens->jwt($user, ['profile:read']);
$tokens->revoke($user->authIdentifier(), $issued->id);
[[/php]]
<p><code>Tokens</code> is a low-level issuance API, not an authorization decision. Never accept arbitrary client-provided scopes or user identifiers. Reauthenticate before creating tokens. JWT login captures the security version before verifying MFA and supplies it to <code>jwt(..., securityVersion: $version)</code>, so a concurrent MFA/security change cannot upgrade an earlier primary-factor check.</p>

<h2>Versioned routes and OpenAPI</h2>
[[php]]
<?php
use PhpOrbit\Api\VersionedRoutes;
use PhpOrbit\Http\Method;

$api = new VersionedRoutes($app->routes, version: 1, title: 'My application API');
$api->add(
    Method::Get,
    '/profile',
    ProfileController::class,
    'api.v1.profile',
    ['summary' => 'Read a profile', 'responses' => [
        '200' => ['description' => 'Current profile'],
        '401' => ['description' => 'Bearer token required'],
    ]],
    scopes: ['profile:read'],
);
$document = $api->document();
// Return Response::json($document->document()) from your documentation handler.
[[/php]]
<p>This registers <code>/api/v1/profile</code>, its name, a Bearer guard, a rate limit and its OpenAPI operation from one declaration. A separate instance with <code>version: 2</code> registers <code>/api/v2/...</code>; versions coexist explicitly. Unknown versions are not redirected to the latest. Route parameters such as <code>{id:\d+}</code> become OpenAPI <code>{id}</code> path parameters. Declare request/response schemas and additional parameters in the operation array; descriptions are not runtime validators. Use <code>JsonBody</code> or your own typed validation.</p>
<p>Bearer protection is on by default. <code>authenticated: false</code> deliberately opts an operation out, as the login endpoint does. Every versioned operation is CSRF-exempt because it must not rely on browser-cookie authority. Do not mount browser state changes in this collection. Use <code>RequireBearer</code> directly for non-versioned routes if needed; it publishes a request-local <code>Principal</code> containing the user, token ID and scopes. Missing or invalid tokens return 401 with <code>WWW-Authenticate</code>; insufficient scopes return 403.</p>

<h2>Rate limiting</h2>
<p>The shared database enforces a fixed window beginning on the first attempt. The lock row is written before reading counters, so simultaneous workers cannot pass a stale check. The demo allows 60 requests per minute, per authenticated user and operation, shared across that user's tokens. Public login has a shared ceiling of 30 per minute plus five attempts per normalized email over fifteen minutes, including successful attempts.</p>
<p>Anonymous limits are deliberately global: no untrusted forwarding header is treated as an identity. For public production APIs add trusted proxy/WAF limits, especially before invalid-token requests, and configure appropriate application limits. Account-wide login limits can be used to temporarily deny a known account access, just as other account-wide abuse controls can. The portable lock serializes short counter updates: this is reliable shared limiting, not a high-throughput Redis limiter.</p>
[[php]]
<?php
$result = $limiter->consume('export:user:' . $userId, limit: 10, windowSeconds: 300);
if (!$result->allowed) {
    // Reject the operation; do not perform it and then check the counter.
}
[[/php]]
<p>Versioned middleware returns <code>X-RateLimit-Limit</code>, <code>X-RateLimit-Remaining</code> and Unix-time <code>X-RateLimit-Reset</code>. Exhaustion returns 429 and a positive <code>Retry-After</code> in seconds. Key identities are hashed in storage. Expired buckets are removed on consumption. Storage errors fail explicitly rather than disabling limits. Consumption owns a database transaction; do not call it from an already-open transaction.</p>

<h2>JSON errors and input</h2>
<p>Register <code>ApiResponses</code> for the <code>/api</code> prefix before other response/error middleware, with a registered <code>Logger</code>. It preserves JSON responses and turns API errors, 404/405 responses and unexpected failures into <code>application/problem+json</code> with <code>type</code>, <code>title</code> and <code>status</code>. Unexpected exception details are logged by class, not exposed to clients, even in debug mode. Non-API pages retain their HTML behavior. Responses are marked <code>no-store</code>.</p>
<p><code>JsonBody::fromRequest($request)</code> accepts a JSON object with <code>Content-Type: application/json</code>, including charset parameters. It rejects malformed JSON (400), bodies above its default 16 KiB bound (413) and other media types (415). <code>string()</code> validates strings without coercing numbers, arrays or objects (422). This parser bound does not replace SAPI/web-server request-size limits.</p>

<h2>Swagger UI without a CDN</h2>
<p>The dedicated <code>/api/docs</code> page loads locally vendored Swagger UI 5.34.1 (Apache-2.0) from <code>public/assets/swagger/</code>; its LICENSE and NOTICE are shipped alongside the assets. The release tarball SHA-512 is <code>VSr8fXfI93obYaQicLJbLUKGRxDojj/SfRJDDLCioGTUBj4BK/Nj4eE9jCeCAnJxVLi7iS+08PbiZLyrQKBkJQ==</code>. Only this page opts into JavaScript; the rest of the demo and generated account forms stay script-free.</p>
<p>The OpenAPI document has no remote server URL, the online validator is disabled, outgoing requests stay same-origin, and authorization is not persisted. Click Authorize and paste a token without the <code>Bearer</code> prefix. Try it out sends real requests, including token issuance and revocation. Do not enter production credentials on an untrusted machine. The page has a separate CSP allowing local scripts and Swagger's inline styles only; the site's default CSP is unchanged elsewhere. No OAuth2 redirect page or remote fonts are required.</p>
HTML,
];
