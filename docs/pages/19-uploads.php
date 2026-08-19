<?php

declare(strict_types=1);

return [
    'slug' => 'uploads',
    'title' => 'File uploads',
    'summary' => 'Quotas, judging files by their bytes, storing them safely, and the cleanup contract.',
    'body' => <<<'HTML'
<div class="warn">
<b>Three things about an upload are attacker-controlled</b>
<p>The filename, the declared media type, and the bytes. None may be trusted, and the API is shaped so that trusting them by accident is difficult.</p>
</div>

<h2>The form</h2>

[[html]]
<form method="post" action="/avatar" enctype="multipart/form-data">
    <input type="hidden" name="_token" value="{{ $csrfToken }}">
    <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp">
    <button type="submit">Upload</button>
</form>
[[/html]]

<p><code>enctype="multipart/form-data"</code> is required — without it the browser sends only the filename.</p>

<h2>The handler</h2>

[[php]]
<?php
final class StoreAvatarController implements Handler
{
    private const ALLOWED = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function handle(ServerRequest $request): Response
    {
        $file = $request->file('avatar');

        if ($file === null || !$file->isValid()) {
            return $this->back($file?->error->message() ?? 'Choose a file.');
        }

        // Judged by contents, never by name or declared type.
        $extension = $file->extensionFromContents(self::ALLOWED);

        if ($extension === null) {
            return $this->back('That is not a PNG, JPEG or WebP image.');
        }

        // A name you generate — not one the client chose.
        $file->moveTo($this->directory, sprintf('user-%d.%s', $userId, $extension));

        return Response::redirect('/avatar');
    }
}
[[/php]]

<h2>Quotas are required</h2>

[[php]]
<?php
use PhpOrbit\Http\Upload\UploadQuotas;

new UploadQuotas(
    maxFileBytes: 2 * 1024 * 1024,
    maxTotalBytes: 8 * 1024 * 1024,
    maxFiles: 5,
    maxFieldBytes: 64 * 1024,
    maxParts: 50,
);

UploadQuotas::permissive();   // 32 MB per file, 64 MB total, 20 files
[[/php]]

<p>An upload endpoint without quotas is a denial-of-service primitive: anyone can post a body large enough to exhaust disk or memory, and repeat it. Quotas are therefore required to parse at all, and the defaults are small enough to be safe on a machine nobody has tuned.</p>

[[ini]]
UPLOAD_MAX_BYTES=1048576
[[/ini]]

<h2>Judging a file</h2>

[[php]]
<?php
$file->clientFilename;      // "photo.png"        — what the browser claimed
$file->clientMediaType;     // "image/png"        — what the browser claimed
$file->size;                // bytes actually received
$file->error;               // UploadError enum

$file->detectedType();      // "image/png" — sniffed from the bytes. Use this.
$file->hasTypeIn(['image/png', 'image/jpeg']);
$file->extensionFromContents(['image/png' => 'png']);
[[/php]]

<div class="good">
<b>Only <code>detectedType()</code> should gate a decision</b>
<p>A file named <code>avatar.png</code> and declared <code>image/png</code> can still be a PHP script. <code>detectedType()</code> inspects the actual bytes with <code>finfo</code>; the filename and declared type are recorded for display and nothing else.</p>
</div>

<h2>Errors are values, not exceptions</h2>

[[php]]
<?php
use PhpOrbit\Http\Upload\UploadError;

match ($file->error) {
    UploadError::None => null,
    UploadError::NoFile => 'No file was selected.',
    UploadError::TooLarge => 'The file is larger than this endpoint accepts.',
    UploadError::Partial => 'The upload was interrupted before it finished.',
    UploadError::TooMany => 'Too many files were sent at once.',
    UploadError::CannotWrite => 'The server could not store the upload.',
};

$file->error->message();   // the same text, ready to show
[[/php]]

<p>A user picking a file that is too large is ordinary form input, so the handler shows a message rather than catching a throwable.</p>

<h2>Storing</h2>

[[php]]
<?php
$path = $file->moveTo($directory, 'user-42.png');
[[/php]]

<p><code>moveTo()</code> refuses a name containing a path separator rather than quietly reducing it, refuses hidden names beginning with a dot, and confirms the resolved destination is a direct child of the directory it was given. Files are stored at mode <code>0640</code> — uploads are data, never programs.</p>

<p>If you must use the client's name, launder it first:</p>

[[php]]
<?php
$file->safeName();               // "my-holiday-photo.png"
$file->safeName('attachment');   // fallback when nothing usable survives
[[/php]]

<p>It strips directories and backslashes, collapses everything outside <code>[A-Za-z0-9._-]</code>, and refuses a leading dot so an upload cannot become <code>.htaccess</code>. It is still not a substitute for generating your own name — two users can upload <code>photo.png</code>.</p>

<h2>Reading without storing</h2>

[[php]]
<?php
$csv = $file->contents();   // throws if invalid or already moved
[[/php]]

<h2>The cleanup contract</h2>

<div class="note">
<b>Temporary files are discarded for you</b>
<p>The kernel schedules cleanup when the request scope opens, so any upload not moved is deleted when the request ends — including when the handler throws. An application that forgot to register an upload-handling layer does not slowly fill its temp directory.</p>
</div>

[[php]]
<?php
$file->wasMoved();   // true once moveTo() succeeded; discard() then does nothing
$file->discard();    // explicit early cleanup, safe to call twice
[[/php]]

<p>The one case where you own it: if you construct a <code>MultipartParser</code> yourself, outside an <code>Application</code>, nothing schedules the cleanup and you must call <code>discard()</code>.</p>

<h2>Where to put the files</h2>

<div class="warn">
<b>Not in the document root</b>
<p>A file under <code>public/</code> is served by nginx or Apache directly, and whether it executes depends on their configuration rather than yours. Store uploads outside the web root and serve them through a route that checks permissions and sets the type.</p>
</div>

[[php]]
<?php
$routes->get('/avatars/{id:\d+}', ServeAvatarController::class, 'avatars.show');
[[/php]]

[[php]]
<?php
return Response::make(Status::Ok, (string) file_get_contents($path))
    ->withHeader('Content-Type', 'image/png')
    ->withHeader('Content-Disposition', 'inline; filename="avatar.png"')
    ->withHeader('Cache-Control', 'private, max-age=3600');
[[/php]]

<h2>How each target decodes uploads</h2>

<div class="scroller">
<table>
<thead><tr><th>Target</th><th>Decoded by</th></tr></thead>
<tbody>
<tr><td>nginx+FPM, Apache, FrankenPHP</td><td>PHP itself, into <code>$_FILES</code>; <code>FpmSapi</code> adapts it</td></tr>
<tr><td><code>./orbit serve</code></td><td><code>MultipartParser</code>, from the body it read</td></tr>
</tbody>
</table>
</div>

<p>Both paths converge on the same <code>UploadedFile</code> objects, so your handler is identical. One difference matters: files PHP created are moved with <code>move_uploaded_file()</code>, which additionally verifies the source really was an upload for this request. <code>UploadedFile</code> tracks which kind it holds and picks the right call.</p>

<h2>Two current limits</h2>

<ul>
<li><strong>Array-style inputs</strong> (<code>name="photos[]"</code>) are not supported. <code>FpmSapi</code> skips them rather than half-supporting PHP's transposed <code>$_FILES</code> arrays. Use distinct field names.</li>
<li><strong>The built-in server decodes from memory</strong>, which bounds upload size there. That is deliberate for a development server; the production targets stream to disk before PHP sees the request.</li>
</ul>
HTML,
];
