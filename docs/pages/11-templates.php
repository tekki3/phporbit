<?php

declare(strict_types=1);

return [
    'slug' => 'templates',
    'title' => 'Templates',
    'summary' => 'Auto-escaping output, control structures, layouts and sections, partials, and shared data.',
    'body' => <<<'HTML'
<p>Templates are <code>app/templates/*.orbit.php</code>. They compile to plain PHP once, then execute as fast as any other included file.</p>

[[php]]
<?php
return $this->view->respond('articles/show', [
    'title' => 'Reading list',
    'article' => $article,
]);
[[/php]]

<h2>Output</h2>

[[html]]
{# Escaped. This is what you want essentially always. #}
<h1>{{ $title }}</h1>

{# Not escaped. Deliberately loud, so it stands out in review. #}
<div>{!! $renderedMarkdown !!}</div>

{# A comment. Removed at compile time; never reaches the browser. #}

{# Literal braces, for Vue, Angular, Handlebars and friends. #}
<span>@{{ notPhp }}</span>
[[/html]]

<div class="good">
<b>The asymmetry is the design</b>
<p><code>{{ }}</code> is shorter and easier to type than <code>{!! !!}</code>. The safe form has to be the convenient one, or people reach for the other by habit. Escaping is not something a template remembers to ask for — it is what output <em>is</em>.</p>
</div>

<h3>What values are allowed</h3>

<p>Strings, numbers, booleans, <code>null</code> and <code>Stringable</code> render. Arrays and plain objects throw:</p>

[[text]]
TemplateError: Cannot render a value of type array. Convert it in the handler
  before passing it to the template.
[[/text]]

<p>Better an error than the string <code>Array</code> appearing in a page, or a fatal from a bare cast.</p>

<h2>Control structures</h2>

[[html]]
@if($articles === [])
    <p>Nothing published yet.</p>
@elseif(count($articles) === 1)
    <p>One article.</p>
@else
    <p>{{ count($articles) }} articles.</p>
@endif

@foreach($articles as $article)
    <article>
        <h2>{{ $article['title'] }}</h2>
        <p>{{ $article['excerpt'] }}</p>
    </article>
@endforeach

@for($page = 1; $page <= $pages; $page++)
    <a href="?page={{ $page }}">{{ $page }}</a>
@endfor

@while($row = $cursor->next())
    <li>{{ $row['name'] }}</li>
@endwhile
[[/html]]

<p>An unrecognised <code>@word</code> passes through untouched — it is far more likely to be prose (an email address, a decorator in a code sample) than a typo'd directive.</p>

<h2>Layouts and sections</h2>

[[html]]
{# app/templates/layout.orbit.php #}
<!doctype html>
<html lang="en">
<head>
    <title>{{ $title }}</title>
    @yield('head')
</head>
<body>
    <main>@yield('content')</main>
</body>
</html>
[[/html]]

[[html]]
{# app/templates/articles/show.orbit.php #}
@extends('layout')

@section('head')
    <meta name="description" content="{{ $article['excerpt'] }}">
@endsection

@section('content')
    <h1>{{ $article['title'] }}</h1>
    {!! $article['html'] !!}
@endsection
[[/html]]

<p>The child renders first, collecting its sections; the layout renders second and pulls them out with <code>@yield</code>. Anything the child emits outside a section becomes the implicit <code>content</code> section, so a layout that yields only <code>content</code> works with a template that never declares one.</p>

<p>A section left unclosed is caught with the template and section named, rather than producing a blank page.</p>

<h2>Partials</h2>

[[html]]
@include('partials/pagination')

{# Extra values are merged over the current ones #}
@include('partials/button')
[[/html]]

<p>A partial sees the including template's data.</p>

<h2>Shared data</h2>

<p>Values every page needs are supplied once, at boot:</p>

[[php]]
<?php
$templates = new TemplateEngine(
    $root . '/app/templates',
    $storage . '/cache/views',
    alwaysRecompile: $debug,
    shared: [
        'appUrl' => $env->string('APP_URL', 'http://localhost:8080'),
        'sapi' => PHP_SAPI,
        'phpVersion' => PHP_VERSION,
    ],
);
[[/php]]

[[html]]
<meta property="og:image" content="{{ $appUrl }}/assets/brand/social-card.png">
<footer>Served by {{ $sapi }} on PHP {{ $phpVersion }}.</footer>
[[/html]]

<p>Per-render data wins over shared data, so a page can override one value without affecting any other page.</p>

<div class="note">
<b>Why constructor, not a setter</b>
<p>A mutable bag on the engine would be per-request state living on a process-lifetime service — one page's values leaking into the next request's render. Supplying them at construction makes that impossible.</p>
</div>

<h2>Compilation and caching</h2>

<p>A template is compiled to PHP and cached under <code>storage/cache/views</code>. It recompiles when the source is newer, or on every render when <code>alwaysRecompile</code> is on — which <code>--debug</code> sets, so edits appear without clearing anything by hand.</p>

<p>Compiled files are written to a temporary name and renamed into place. A worker must never <code>require</code> a file another process is halfway through writing.</p>

<p>The staleness check compares file modification times, which a deploy method that preserves them (some <code>rsync</code> and tarball-extraction flows do) can defeat. <code>orbit storage:clear</code> deletes everything under <code>storage/cache/views</code> unconditionally — safe at any time, since the next render just recompiles what it finds missing.</p>

<h2>Two things templates cannot do</h2>

<p><strong>Raw PHP tags are neutralised.</strong> A literal <code>&lt;?</code> renders as text. Every executable construct comes from a directive the compiler knows about, which keeps templates readable and stops a stray tag from becoming a surprise.</p>

<p><strong>Template names are validated, not sanitised.</strong> Names may contain letters, digits, underscores, hyphens and forward slashes:</p>

[[php]]
<?php
$view->render('../../../etc/passwd');
// TemplateError: Invalid template name "../../../etc/passwd". Names may contain
//   letters, digits, underscores, hyphens and forward slashes only.
[[/php]]

<p>A name that reached the engine from a request could otherwise compile and execute an arbitrary file.</p>

<h2>Rendering without a response</h2>

[[php]]
<?php
$html = $this->view->render('emails/welcome', ['name' => $user->name]);

$this->view->exists('emails/welcome');   // true
[[/php]]

<h2>Escaping inside a template</h2>

<p><code>{{ }}</code> escapes for HTML text. For other contexts, call the escaper explicitly:</p>

[[html]]
<a href="{!! PhpOrbit\Security\Escaper::urlAttribute($link) !!}">Open</a>

<button data-name="{!! PhpOrbit\Security\Escaper::attribute($name) !!}">Save</button>

<script>
    const user = {!! PhpOrbit\Security\Escaper::js($name) !!};
</script>
[[/html]]

<p>Note that <code>js()</code> includes its own quotes — do not add more. <a href="security.html">Escaping in detail &rarr;</a></p>
HTML,
];
