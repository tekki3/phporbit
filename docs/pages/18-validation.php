<?php

declare(strict_types=1);

return [
    'slug' => 'validation',
    'title' => 'Validation',
    'summary' => 'Checking form input, collecting errors, and redisplaying a form without losing what the user typed.',
    'body' => <<<'HTML'
<p><code>Validator</code> is a small chainable checker over string form fields. It collects the first error per field rather than throwing, because a form with three problems should show three problems.</p>

[[php]]
<?php
use PhpOrbit\Validation\Validator;

$validator = Validator::forRequest($request)
    ->required('title')
    ->maxLength('title', 120)
    ->required('email')
    ->email('email')
    ->integer('quantity')
    ->in('visibility', ['public', 'unlisted']);

if ($validator->fails()) {
    // ...
}
[[/php]]

<h2>Rules</h2>

<div class="scroller">
<table>
<thead><tr><th>Rule</th><th>Passes when</th></tr></thead>
<tbody>
<tr><td><code>required($field)</code></td><td>Present and not blank after trimming.</td></tr>
<tr><td><code>minLength($field, $min)</code></td><td>At least <code>$min</code> characters.</td></tr>
<tr><td><code>maxLength($field, $max)</code></td><td>At most <code>$max</code> characters.</td></tr>
<tr><td><code>integer($field)</code></td><td>Digits, optionally signed.</td></tr>
<tr><td><code>email($field)</code></td><td>Passes <code>FILTER_VALIDATE_EMAIL</code>.</td></tr>
<tr><td><code>in($field, $allowed)</code></td><td>Exactly matches one of the allowed values.</td></tr>
</tbody>
</table>
</div>

<p>Length is counted in characters, not bytes, so an accented name is not rejected for being &ldquo;too long&rdquo;.</p>

<div class="note">
<b>Absent fields skip non-required rules</b>
<p>Only <code>required()</code> complains about a missing field. That way an optional field with a <code>maxLength()</code> does not produce a spurious error when it is simply not filled in — state both rules when you want both.</p>
</div>

<h2>Results</h2>

[[php]]
<?php
$validator->passes();              // bool
$validator->fails();               // bool
$validator->error('title');        // ?string — the first error for one field
$validator->errors();              // array<string, string> — one per failed field
$validator->value('title');        // ?string — the raw submitted value
$validator->validated('title');    // string — throws if that field failed
[[/php]]

<p><code>validated()</code> is the one to use after checking <code>passes()</code>. It returns a plain <code>string</code>, so the rest of your code is not threading <code>?string</code> around:</p>

[[php]]
<?php
if ($validator->fails()) {
    return $this->redisplay($validator, $request);
}

$this->database->query('articles')->insert([
    'title' => $validator->validated('title'),
    'body' => $validator->validated('body'),
]);
[[/php]]

<p>Calling it on a field that failed throws — it is a programming error to use a value you were told was invalid.</p>

<h2>Custom messages</h2>

[[php]]
<?php
$validator = Validator::forRequest($request)
    ->required('title', 'Give your article a title.')
    ->maxLength('title', 120, 'Titles are limited to 120 characters.');
[[/php]]

<h2>Redisplaying a form</h2>

<p>Re-render rather than redirect, so the user keeps what they typed:</p>

[[php]]
<?php
if ($validator->fails()) {
    return $this->view->respond('articles/new', [
        'title' => 'New article',
        'errors' => $validator->errors(),
        'old' => $request->formData(),
    ], Status::UnprocessableEntity);
}
[[/php]]

[[html]]
<form method="post" action="/articles">
    <input type="hidden" name="_token" value="{{ $csrfToken }}">

    <label for="title">Title</label>
    <input id="title" name="title" value="{{ $old['title'] ?? '' }}"
           @if(isset($errors['title'])) aria-invalid="true" aria-describedby="title-error" @endif>

    @if(isset($errors['title']))
        <p class="error" id="title-error">{{ $errors['title'] }}</p>
    @endif

    <button type="submit">Publish</button>
</form>
[[/html]]

<p><code>422 Unprocessable Entity</code> is the honest status: the request was well-formed but the contents were not acceptable.</p>

<h2>Checks the validator does not do</h2>

<p>Rules that need the database — uniqueness, existence, ownership — belong in the controller, where the query is visible:</p>

[[php]]
<?php
if ($validator->passes()) {
    $taken = $this->database->query('users')
        ->where('email', '=', $validator->validated('email'))
        ->exists();

    if ($taken) {
        return $this->redisplay(['email' => 'That address is already registered.'], $request);
    }
}
[[/php]]

<div class="warn">
<b>Careful with what that reveals</b>
<p>&ldquo;Already registered&rdquo; on a public sign-up form tells an attacker which addresses have accounts. If that matters for your application, accept the registration and send an email that either welcomes them or says an account already exists.</p>
</div>

<h2>Validating an upload</h2>

<p>Uploads are not form fields; check them separately, and always by content:</p>

[[php]]
<?php
$avatar = $request->file('avatar');

if ($avatar === null || !$avatar->isValid()) {
    $errors['avatar'] = $avatar?->error->message() ?? 'Choose a file.';
} elseif (!$avatar->hasTypeIn(['image/png', 'image/jpeg', 'image/webp'])) {
    $errors['avatar'] = 'That is not a PNG, JPEG or WebP image.';
}
[[/php]]

<p><a href="uploads.html">Uploads &rarr;</a></p>

<h2>Beyond these rules</h2>

<p>The rule set is deliberately small — enough for a form, not a schema language. For anything richer, validate in a dedicated class and return your own error array; nothing in the framework requires <code>Validator</code>:</p>

[[php]]
<?php
final class ArticleInput
{
    /** @return array<string, string> field => message */
    public function check(ServerRequest $request): array { /* … */ }
}
[[/php]]
HTML,
];
