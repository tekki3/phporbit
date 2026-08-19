<?php

declare(strict_types=1);

return [
    'slug' => 'encryption',
    'title' => 'Encryption and signing',
    'summary' => 'Authenticated encryption for secrets, signatures for values that are not secret, and the key that backs both.',
    'body' => <<<'HTML'
<p>Two tools, and picking the right one is most of the work:</p>

<div class="scroller">
<table>
<thead><tr><th>You need</th><th>Use</th><th>Example</th></tr></thead>
<tbody>
<tr><td>Nobody may <em>read</em> it</td><td><code>Encrypter</code></td><td>An API token stored in a column</td></tr>
<tr><td>Nobody may <em>change</em> it</td><td><code>Signer</code></td><td>An unsubscribe link, a preference cookie</td></tr>
<tr><td>Nobody may read it, ever, including you</td><td><code>PasswordHasher</code></td><td>Passwords — see <a href="auth.html">Authentication</a></td></tr>
</tbody>
</table>
</div>

<p>Encrypting something that was never secret hides it from the person it belongs to while costing exactly as much as signing it. Hashing a password is not encryption: there is no key, and no way back.</p>

<h2>The key</h2>

[[bash]]
$ orbit key:generate
base64:5ir8ux27BkCx3eHiXYqy2QlQegShL3Tg0A57hrGIa1I=
[[/bash]]

[[ini]]
APP_KEY=base64:5ir8ux27BkCx3eHiXYqy2QlQegShL3Tg0A57hrGIa1I=

# Retired by a rotation: still read old data, never used to write new.
#APP_PREVIOUS_KEYS=base64:…,base64:…
[[/ini]]

<p><code>orbit new</code> generates one per project. The command prints rather than writes, so it is safe to run anywhere and makes plain that the value belongs in configuration your deployment supplies.</p>

<div class="note">
<b>A blank key is not a key</b>
<p><code>APP_KEY</code> is read with <code>required()</code>, so <code>APP_KEY=</code> fails exactly as an absent one does. It is resolved on first use rather than at boot, so an application that never encrypts anything never needs one.</p>
</div>

<h2>Encrypting</h2>

[[php]]
<?php
use PhpOrbit\Crypto\Encrypter;

final class StoreTokenController implements Handler
{
    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly Connection $database,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $this->database->query('integrations')->insert([
            'provider' => 'stripe',
            'token' => $this->encrypter->encrypt($request->form('token') ?? ''),
        ]);

        return Response::redirect('/integrations');
    }
}
[[/php]]

[[php]]
<?php
$token = $encrypter->encrypt('sk_live_…');
// v1.k7Fx…  — base64url, safe in a URL, a cookie or a header

$encrypter->decrypt($token);      // throws DecryptionFailed if anything is wrong
$encrypter->tryDecrypt($token);   // null instead, for when a bad value is expected
[[/php]]

<p>XChaCha20-Poly1305, with the tag verified before a single byte is returned. There is no mode selector and no way to disable authentication: unauthenticated encryption is not a trade-off this framework offers, because the cases where it is genuinely safe are rare and the cases where it looks safe are not.</p>

<p>The nonce is random per message, so the same plaintext never encrypts to the same token — otherwise an observer learns which rows are equal without decrypting any of them.</p>

<h2>Binding a ciphertext to where it belongs</h2>

<p>The second argument is authenticated but not encrypted. It is not recoverable from the token, and decryption fails unless the same value is supplied again.</p>

[[php]]
<?php
$token = $encrypter->encrypt($email, 'users.email:' . $user->id);

$encrypter->decrypt($token, 'users.email:' . $user->id);   // fine
$encrypter->decrypt($token, 'users.email:999');            // DecryptionFailed
[[/php]]

<div class="good">
<b>What this stops</b>
<p>An attacker who can write to the database but cannot decrypt can still <em>move</em> a ciphertext — copying the administrator's encrypted email into their own row, so the application decrypts it and shows it to them. Binding the context makes that ciphertext invalid anywhere but its own row.</p>
</div>

<h2>Signing</h2>

[[php]]
<?php
use PhpOrbit\Crypto\Signer;

// A reset link that stops working in an hour.
$token = $signer->sign('reset:' . $user->id, ttlSeconds: 3600);
$url = $appUrl . '/reset/' . $token;

// Later
$value = $signer->verify($token);   // null if forged, altered or expired

if ($value === null) {
    return Response::text('That link is no longer valid.', Status::Forbidden);
}
[[/php]]

<p>The value stays readable — that is the point — but changing it invalidates the signature. The expiry is inside the signed payload, so moving the deadline requires the key.</p>

<div class="warn">
<b>A signature with no expiry lasts until the key changes</b>
<p>Which, for a password-reset link, is far too long. Pass <code>ttlSeconds</code> for anything that should stop working. Omitting it is right for a preference cookie and wrong for almost everything else.</p>
</div>

<h2>Rotating keys</h2>

[[ini]]
APP_KEY=base64:<the new key>
APP_PREVIOUS_KEYS=base64:<the old one>
[[/ini]]

<p>New values use the new key; old values still decrypt. Once you have re-encrypted the stored data, drop the old key from the list and it stops being accepted.</p>

[[php]]
<?php
// Re-encrypting a column after a rotation
foreach ($database->query('integrations')->get() as $row) {
    $database->query('integrations')
        ->where('id', '=', (int) $row['id'])
        ->update(['token' => $encrypter->encrypt($encrypter->decrypt((string) $row['token']))]);
}
[[/php]]

<h2>Things that are not adjustable</h2>

<ul>
<li><strong>The algorithm.</strong> One primitive, chosen once. A cipher selector is a way for a future reader to pick a worse one.</li>
<li><strong>Authentication.</strong> Every ciphertext carries a tag, and it is checked first.</li>
<li><strong>The failure message.</strong> Wrong key, tampered ciphertext, truncated token and wrong context all produce the same exception and the same wording. Distinguishing them tells an attacker which part of a forgery was closest, which is what turns guessing into an attack.</li>
<li><strong>Key separation.</strong> Signing and encryption derive independent keys from <code>APP_KEY</code>, so one configured secret never becomes one shared key.</li>
</ul>

<h2>Keeping the key out of everything else</h2>

[[php]]
<?php
$key = Key::generate();

print_r($key);        // ['key' => '<redacted>']
(string) $key;        // '<redacted>'
serialize($key);      // InvalidArgumentException
[[/php]]

<p>Keys escape through logs, dumps and serialised state far more often than through cryptanalysis. A serialised key lands in a session file, a cache entry or a queue payload, all of which outlive the process, so it is refused outright.</p>

<div class="note">
<b>There is no <code>sodium_memzero</code> here, deliberately</b>
<p>It would wipe one buffer while PHP has already copied the bytes several times over — <code>base64_encode</code>, <code>hash_hmac</code> and the sodium calls each take their own. Wiping one copy buys a false sense of having scrubbed the process. The defences above are the ones that work.</p>
</div>

<h2>Not built</h2>

<ul>
<li><strong>Encrypted model attributes.</strong> There is no ORM to hang them on; encrypt in the repository that owns the column, where the context is obvious.</li>
<li><strong>Asymmetric encryption and signatures.</strong> Two parties with separate keys is a different problem, and one where the sensible answer is usually a library built for the specific protocol.</li>
<li><strong>Key management beyond <code>.env</code>.</strong> For a KMS or a secrets manager, fetch the key at boot and pass it to <code>new Encrypter(new Key($bytes))</code>.</li>
</ul>
HTML,
];
