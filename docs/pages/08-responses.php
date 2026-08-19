<?php

declare(strict_types=1);

return [
    'slug' => 'responses',
    'title' => 'Responses',
    'summary' => 'Building responses, status codes, headers, cookies, and the security defaults you get for free.',
    'body' => <<<'HTML'
<p><code>Response</code> is immutable. Constructors are named for what you are sending.</p>

[[php]]
<?php
use PhpOrbit\Http\Response;
use PhpOrbit\Http\Status;

Response::text('Hello.');                             // text/plain; charset=utf-8
Response::html('<h1>Hello</h1>');                     // text/html; charset=utf-8
Response::json(['id' => 42]);                         // application/json; charset=utf-8
Response::redirect('/articles');                      // 302 with Location
Response::redirect('/articles', Status::MovedPermanently);
Response::noContent();                                // 204, body suppressed
Response::make(Status::Created, $body);               // anything else
[[/php]]

<p>Every one of these states a charset. Omitting it invites the browser to sniff the encoding, which is an XSS vector.</p>

<h2>Status codes</h2>

[[php]]
<?php
Response::text('Not found.', Status::NotFound);
Response::json($errors, Status::UnprocessableEntity);

$response->status;                    // Status enum
$response->status->value;             // 404
$response->status->reasonPhrase();    // "Not Found"
$response->status->allowsBody();      // false for 204 and 304
[[/php]]

<p>Available cases cover the common set: <code>Ok</code>, <code>Created</code>, <code>NoContent</code>, <code>MovedPermanently</code>, <code>Found</code>, <code>NotModified</code>, <code>BadRequest</code>, <code>Unauthorized</code>, <code>Forbidden</code>, <code>NotFound</code>, <code>MethodNotAllowed</code>, <code>Conflict</code>, <code>PayloadTooLarge</code>, <code>UnprocessableEntity</code>, <code>TooManyRequests</code>, <code>InternalServerError</code>, <code>NotImplemented</code>, <code>ServiceUnavailable</code>.</p>

<h2>Security headers, by default</h2>

<p>Every response carries these unless you override them:</p>

[[text]]
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: no-referrer
Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'
[[/text]]

<div class="good">
<b>Why not middleware</b>
<p>They are applied in <code>Response</code> itself rather than by a layer. A 500 built on the error path — where middleware may have been skipped entirely — still carries them. A header that only appears when everything went well is a header you cannot rely on.</p>
</div>

<p>Overriding is per response, when a page genuinely needs it:</p>

[[php]]
<?php
return Response::html($embeddable)
    ->withHeader('X-Frame-Options', 'SAMEORIGIN')
    ->withHeader('Content-Security-Policy', "default-src 'self'; img-src https:");
[[/php]]

<h2>Headers</h2>

[[php]]
<?php
$response
    ->withHeader('Cache-Control', 'no-store')      // replaces
    ->withAddedHeader('Vary', 'Accept-Encoding')   // appends
    ->withStatus(Status::Created)
    ->withBody($newBody);
[[/php]]

<p><code>Content-Length</code> is computed by the adapter — you never set it.</p>

<h2>Cookies</h2>

[[php]]
<?php
use PhpOrbit\Http\Cookie;
use PhpOrbit\Http\SameSite;

// Defaults: HttpOnly, SameSite=Lax, Path=/, Secure.
return Response::redirect('/')->withCookie(
    Cookie::forRequest($request, 'theme', 'dark', expires: time() + 86400),
);
[[/php]]

<div class="note">
<b>Why <code>forRequest()</code></b>
<p><code>Secure</code> cannot simply default to true: a Secure cookie is never sent over plain HTTP, which would silently break the built-in server on <code>http://localhost</code>. <code>forRequest()</code> reads the scheme off the request, so the flag is right in development and in production without a conditional at the call site.</p>
</div>

[[php]]
<?php
// Full control when you need it
new Cookie(
    name: 'preferences',
    value: $encoded,
    expires: time() + 2592000,
    path: '/',
    domain: null,
    secure: true,
    httpOnly: false,               // deliberately readable by script
    sameSite: SameSite::Strict,
);

// Removing one — attributes must match those it was set with
Response::redirect('/')->withCookie(
    Cookie::expired('theme', secure: $request->uri->isSecure()),
);
[[/php]]

<p>Cookie names and values are validated: a control character, space, comma, semicolon, quote or backslash in a value throws rather than being silently encoded, because those characters end the attribute early and let the rest forge cookie attributes of its own. Encode the value first — base64 or URL-encoding — if it might contain them.</p>

<p><code>SameSite::None</code> without <code>Secure</code> also throws, since browsers reject that combination anyway.</p>

<h2>JSON and HTML injection</h2>

[[php]]
<?php
Response::json(['note' => '</script><script>alert(1)</script>']);
// {"note":"<\/script><script>alert(1)<\/script>"}
[[/php]]

<p>JSON is encoded with the <code>JSON_HEX_*</code> flags, so a payload containing <code>&lt;/script&gt;</code> cannot break out if the response is inlined into a page.</p>

<h2>HEAD requests</h2>

<p>A <code>HEAD</code> is routed to the matching <code>GET</code> handler; the kernel strips the body afterwards. Your handler never checks for it. Headers stay identical, which is the point of the method.</p>

<h2>204 and 304</h2>

[[php]]
<?php
$response = Response::noContent()->withBody('ignored');

$response->body;        // "ignored"  — what you set
$response->wireBody();  // ""         — what is actually sent
[[/php]]

<p>Responses that must not carry a body do not, regardless of what the handler built. The adapters send <code>wireBody()</code>.</p>

<h2>Rendering templates</h2>

[[php]]
<?php
// TemplateEngine::respond() is Response::html() around a render.
return $this->view->respond('articles/show', [
    'title' => $article['title'],
    'article' => $article,
]);

return $this->view->respond('articles/new', $data, Status::UnprocessableEntity);
[[/php]]

<h2>Error responses</h2>

<p>Uncaught exceptions become a 500. Outside debug mode the body says nothing about the cause:</p>

[[text]]
Internal Server Error
[[/text]]

<p>With <code>--debug</code>, the class, message, file, line and stack trace are rendered instead. Exception messages routinely contain file paths, SQL and credentials, which is why that is opt-in and never the default.</p>
HTML,
];
