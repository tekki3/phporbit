<?php

declare(strict_types=1);

return [
    'slug' => 'requests',
    'title' => 'Requests',
    'summary' => 'Reading the method, URL, query string, headers, cookies, form fields and uploads.',
    'body' => <<<'HTML'
<p><code>ServerRequest</code> is an immutable value object, fully detached from the SAPI that produced it. Every field is materialised by the adapter, so the same object is built identically under all four targets — and no code above the adapter ever reads a superglobal.</p>

<h2>At a glance</h2>

[[php]]
<?php
public function handle(ServerRequest $request): Response
{
    $request->method;                     // Method enum
    $request->uri->path;                  // "/articles/42"
    $request->uri->queryParam('page');    // "2" | null
    $request->headers->first('Accept');   // "text/html" | null
    $request->cookie('theme');            // "dark" | null
    $request->attribute('id');            // route parameter
    $request->form('title');              // POST field
    $request->file('avatar');             // UploadedFile | null
    $request->body;                       // raw body, for JSON and webhooks
}
[[/php]]

<h2>Method</h2>

[[php]]
<?php
use PhpOrbit\Http\Method;

if ($request->method === Method::Post) {
    // ...
}

// Safe methods are exempt from CSRF; this is what decides that.
$request->method->isStateChanging();   // false for GET, HEAD, OPTIONS
[[/php]]

<h2>URL</h2>

[[php]]
<?php
$uri = $request->uri;

$uri->scheme;                  // "https"
$uri->host;                    // "example.test"
$uri->port;                    // 443
$uri->path;                    // "/search"   (decoded, dot segments resolved)
$uri->isSecure();              // true
$uri->authority();             // "example.test"  (default port omitted)
(string) $uri;                 // "https://example.test/search?q=cats"

$uri->queryParam('q');         // "cats" | null
$uri->queryParams();           // ['q' => 'cats', 'page' => '2']
[[/php]]

<div class="note">
<b>The path is already safe</b>
<p><code>Uri</code> splits on <code>/</code> <em>before</em> percent-decoding, so an encoded separator can never introduce a segment, and it matches dot segments <em>after</em> decoding, so <code>%2E%2E</code> is caught as traversal. Encoded separators are rejected outright, and a path climbing above the root is refused rather than clamped. By the time your handler sees <code>$uri-&gt;path</code>, those attacks are already 400s.</p>
</div>

<h2>Headers</h2>

<p>Lookup is case-insensitive; the original casing is preserved for the wire.</p>

[[php]]
<?php
$headers = $request->headers;

$headers->has('authorization');            // true
$headers->first('Content-Type');           // "application/json" | null
$headers->all('Accept-Encoding');          // ['gzip', 'br']
$headers->toWire();                        // [['Accept', 'text/html'], ...]

// Immutable — these return copies
$headers->with('X-Trace', 'abc');          // replaces
$headers->add('X-Trace', 'def');           // appends
$headers->without('Cookie');
[[/php]]

<p>Header names and values are validated on construction: a CR, LF or NUL is rejected rather than stripped, so response splitting is not possible through a value that came from a request.</p>

<h2>Query string versus form fields</h2>

[[php]]
<?php
// GET /search?q=cats
$request->uri->queryParam('q');   // "cats"

// POST with application/x-www-form-urlencoded
$request->form('title');          // "Hello"
$request->formData();             // ['title' => 'Hello', '_token' => '...']
[[/php]]

<p>Form decoding happens in the SAPI adapter while the request is built, so the value object stays immutable with no lazy parsing hidden inside it.</p>

<div class="warn">
<b>Nested inputs are dropped</b>
<p><code>a[b]=1</code> decodes to an array, and this API is string-typed throughout. Nested structures are dropped rather than flattened into something ambiguous. For structured input, post JSON and decode <code>$request-&gt;body</code> yourself.</p>
</div>

<h2>JSON bodies</h2>

[[php]]
<?php
$payload = json_decode($request->body, true, 512, JSON_THROW_ON_ERROR);

if (!is_array($payload)) {
    return Response::json(['error' => 'Expected an object.'], Status::BadRequest);
}

$title = is_string($payload['title'] ?? null) ? $payload['title'] : null;
[[/php]]

<p>The framework does not decode JSON for you: the shape is yours to narrow, and doing it at the edge is what keeps <code>mixed</code> out of the rest of your code.</p>

<h2>Route parameters</h2>

[[php]]
<?php
// Route: /users/{id}/posts/{slug}
$request->attribute('id');      // "42"
$request->attribute('slug');    // "hello-world"
$request->attributes();         // ['id' => '42', 'slug' => 'hello-world']
[[/php]]

<p>Attributes are also how middleware annotates a request for layers further in:</p>

[[php]]
<?php
public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
{
    return $next($request->withAttribute('requestId', bin2hex(random_bytes(8))));
}
[[/php]]

<h2>Cookies</h2>

[[php]]
<?php
$request->cookie('orbit_session');   // string | null
$request->cookies();                 // ['orbit_session' => '...', 'theme' => 'dark']
[[/php]]

<p>Setting cookies is a <a href="responses.html">response</a> concern.</p>

<h2>Uploads</h2>

[[php]]
<?php
$avatar = $request->file('avatar');       // UploadedFile | null
$request->files();                        // ['avatar' => UploadedFile]

if ($avatar !== null && $avatar->isValid()) {
    $stored = $avatar->moveTo($directory, 'user-42.png');
}
[[/php]]

<p><a href="uploads.html">Uploads have their own page</a> — quotas, content sniffing and the cleanup contract matter more than the accessor.</p>

<h2>Immutability</h2>

<p>Every <code>with*</code> method returns a copy. The original is never modified, which is what makes it safe to hand the same request to several layers.</p>

[[php]]
<?php
$one = $request->withAttribute('a', '1');
$two = $one->withAttribute('b', '2');

$request->attribute('a');   // null — untouched
$two->attribute('a');       // "1"
[[/php]]

<h2>Building one in a test</h2>

[[php]]
<?php
use PhpOrbit\Http\Headers;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Uri;

$request = new ServerRequest(
    Method::Post,
    Uri::fromRequestTarget('/articles?draft=1', 'http', 'localhost', 8080),
    Headers::fromArray(['Content-Type' => 'application/x-www-form-urlencoded']),
    body: 'title=Hello',
    cookies: ['orbit_session' => $id],
    form: ['title' => 'Hello'],
);
[[/php]]

<p>The suite ships a helper that does this for you — see <a href="testing.html">Testing</a>.</p>
HTML,
];
