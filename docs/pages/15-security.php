<?php

declare(strict_types=1);

return [
    'slug' => 'security',
    'title' => 'Security',
    'summary' => 'Context-aware escaping, CSRF, security headers, and the attacks the framework handles before your code runs.',
    'body' => <<<'HTML'
<p>The rule throughout: <strong>the safe path is the default path</strong>. You do not opt into safety; you opt out of it, visibly.</p>

<h2>Escaping</h2>

<p>There is no single &ldquo;escape&rdquo; function that is correct everywhere. A value safe inside an HTML element is still dangerous in an unquoted attribute, a script block or a URL — so each context has its own method.</p>

[[php]]
<?php
use PhpOrbit\Security\Escaper;

Escaper::html($value);           // text inside an element
Escaper::attribute($value);      // an attribute value
Escaper::js($value);             // a string literal in <script> — includes quotes
Escaper::url($value);            // one query parameter or path segment
Escaper::urlAttribute($url);     // a whole URL for href/src
[[/php]]

<p>In templates, <code>{{ }}</code> calls <code>html()</code> for you. The others are explicit:</p>

[[html]]
<p>{{ $name }}</p>

<button data-user="{!! Escaper::attribute($name) !!}">Save</button>

<a href="{!! Escaper::urlAttribute($link) !!}">Open</a>

<script>
    const user = {!! Escaper::js($name) !!};
</script>
[[/html]]

<h3>Why attribute() is so aggressive</h3>

<p>It hex-encodes every character outside <code>[a-zA-Z0-9,._-]</code>, which stays safe even when the template author forgot the quotes:</p>

[[html]]
{# Both are safe, which is the point #}
<div title={!! Escaper::attribute($value) !!}>
<div title="{!! Escaper::attribute($value) !!}">
[[/html]]

<h3>js() brings its own quotes</h3>

[[php]]
<?php
Escaper::js('hello "world"');   // "hello "world""

// WRONG — you end up with doubled quotes
// <script>const x = "{!! Escaper::js($v) !!}";</script>

// RIGHT
// <script>const x = {!! Escaper::js($v) !!};</script>
[[/php]]

<p>It encodes as JSON, including the surrounding quotes, because manual backslash escaping reliably misses a case.</p>

<h3>urlAttribute() neutralises dangerous schemes</h3>

[[php]]
<?php
Escaper::urlAttribute('https://example.test/page');   // escaped, allowed
Escaper::urlAttribute('/local/path');                 // relative, allowed
Escaper::urlAttribute('mailto:a@example.test');       // allowed

Escaper::urlAttribute('javascript:alert(1)');         // "#"
Escaper::urlAttribute('data:text/html,<script>…');    // "#"
Escaper::urlAttribute('  javascript:alert(1)');       // "#" — leading space too
[[/php]]

<p>Only <code>http</code>, <code>https</code> and <code>mailto</code> survive. Returning a harmless value rather than throwing keeps one hostile link from taking down a whole page render.</p>

<h2>CSRF</h2>

<p>Protection is on for every state-changing method. A form needs the token:</p>

[[html]]
<form method="post" action="/articles">
    <input type="hidden" name="_token" value="{{ $csrfToken }}">
    <input name="title">
    <button type="submit">Publish</button>
</form>
[[/html]]

[[php]]
<?php
use PhpOrbit\Security\Csrf;

// In a controller
$token = Csrf::token($session);

// Or a ready-made input
$field = Csrf::field($session);   // <input type="hidden" name="_token" value="…">
[[/php]]

<p>For fetch/XHR, send it as a header instead:</p>

[[php]]
<?php
// X-CSRF-Token: <token>
[[/php]]

<h3>Opting a route out</h3>

[[php]]
<?php
// A webhook authenticates by signature, not by session — there is no token to send.
$routes->add(Method::Post, '/webhooks/stripe', StripeWebhook::class, 'webhooks.stripe', csrfExempt: true);
[[/php]]

<p>Per route, and explicit. Verify the signature in the handler.</p>

<h3>How it works</h3>

<ul>
<li>The token is 256 bits from the CSPRNG, stored in the session, minted on first use.</li>
<li>It is bound to the session rather than one form, so several open tabs do not invalidate each other.</li>
<li>Comparison uses <code>hash_equals</code> — <code>===</code> would leak the correct prefix through timing.</li>
<li>Safe methods (GET, HEAD, OPTIONS) skip the check entirely.</li>
<li><code>Csrf::rotate()</code> discards the token; the authenticator calls it on login.</li>
</ul>

<p>A missing or wrong token gives <code>403 CSRF token missing or invalid.</code></p>

<h2>Security headers</h2>

<p>Applied to every response, including error pages:</p>

[[text]]
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: no-referrer
Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'
[[/text]]

<p>They live in <code>Response</code> rather than middleware so a response built on the error path still carries them. <a href="responses.html">Overriding them &rarr;</a></p>

<h2>Handled before your code runs</h2>

<h3>Path traversal</h3>

[[bash]]
$ curl -i 'http://127.0.0.1:8080/files/../../etc/passwd'
HTTP/1.1 400 Bad Request

$ curl -i 'http://127.0.0.1:8080/files/%2E%2E%2F%2E%2E%2Fetc'
HTTP/1.1 400 Bad Request
[[/bash]]

<p><code>Uri</code> splits on <code>/</code> <em>before</em> decoding, so an encoded separator cannot create a segment, and matches dot segments <em>after</em> decoding, so <code>%2E%2E</code> is caught. Encoded separators are rejected; a path climbing above the root is refused rather than clamped.</p>

<h3>Header and response splitting</h3>

[[php]]
<?php
Headers::empty()->with('X-Note', "one\r\nX-Injected: yes");
// MalformedRequest: Header values may not contain CR, LF or NUL.
[[/php]]

<p>Rejected, not stripped. Same for cookie values, which additionally refuse spaces, quotes, commas, semicolons and backslashes.</p>

<h3>Request flooding</h3>

<p><code>RequestParser</code> bounds every read: request line, header count, total header bytes, body size. An HTTP parser without limits is a memory-exhaustion primitive — a client that opens a connection and streams header bytes forever would otherwise consume the whole process.</p>

<h3>Host header poisoning</h3>

<p><code>FpmSapi</code> prefers <code>SERVER_NAME</code> (your web server's configuration) over the client-supplied <code>Host</code>, so a forged Host cannot poison generated URLs or password-reset links. <code>X-Forwarded-Proto</code> is believed only from a configured trusted proxy:</p>

[[ini]]
TRUSTED_PROXIES=10.0.0.1,10.0.0.2
[[/ini]]

<p>Believing it unconditionally would let anyone claim their plaintext request was HTTPS and unlock Secure-only cookies.</p>

<h2>Errors keep their secrets</h2>

[[text]]
# production
Internal Server Error

# --debug
RuntimeException: SQLSTATE[HY000] … user=admin password=hunter2
  in /srv/app/src/Database/Connection.php:142
[[/text]]

<p>Exception messages carry paths, SQL and credentials, so detail is opt-in. The same applies to configuration: parse errors name the key and line but never the value, and <code>Environment::__debugInfo()</code> redacts everything.</p>

<h2>A checklist for your own code</h2>

<ul>
<li>Use <code>{{ }}</code>. Reach for <code>{!! !!}</code> only for markup <em>you</em> built.</li>
<li>Never build SQL by interpolation — there is no API that accepts it, so this is mostly about hand-written strings.</li>
<li>Map user input to identifiers through an allowlist before <code>orderBy()</code>.</li>
<li>Judge uploads by <code>detectedType()</code>, never by filename or declared type.</li>
<li>Call <code>$auth->login()</code> rather than writing the user id into the session yourself — it regenerates the session id and rotates the CSRF token.</li>
<li>Put <code>required()</code> on secrets in configuration, so a blank value fails at boot.</li>
</ul>
HTML,
];
