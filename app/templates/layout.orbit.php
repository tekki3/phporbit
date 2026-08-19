<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>

    {# SVG first for browsers that take it; .ico is the fallback for the rest. #}
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">

    {# Told to the browser so form controls and scrollbars match the page. #}
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="#10131a" media="(prefers-color-scheme: dark)">
    <meta name="theme-color" content="#fbfcfd" media="(prefers-color-scheme: light)">

    {# Link previews. og:image must be absolute — scrapers do not resolve
       relative paths — which is why APP_URL is shared with every template. #}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="phporbit">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="A safe PHP framework that runs on itself.">
    <meta property="og:image" content="{{ $appUrl }}/assets/brand/social-card.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<a class="skip" href="#content">Skip to content</a>

<header class="masthead">
    {# The lockup carries the name, so the link needs no separate text. The
       wordmark is a fixed colour, so the page picks the variant that contrasts
       with the reader's theme. #}
    <a class="brand" href="/">
        <picture>
            <source srcset="/assets/brand/phporbit-logo-on-dark.svg" media="(prefers-color-scheme: dark)">
            <img src="/assets/brand/phporbit-logo-on-light.svg" alt="phporbit" width="91" height="26">
        </picture>
    </a>

    <nav>
        <a href="/" @if(($currentPath ?? '') === '/') class="current" aria-current="page" @endif>Self-check</a>
        <a href="/notes" @if(($currentPath ?? '') === '/notes') class="current" aria-current="page" @endif>Notes</a>
        <a href="/contact" @if(($currentPath ?? '') === '/contact') class="current" aria-current="page" @endif>Contact</a>
        <a href="/hello/world" @if(($currentPath ?? '') === '/hello') class="current" aria-current="page" @endif>Escaping</a>
        @if($hasDocs)
            <a href="/docs/">Docs</a>
        @endif

        @if(($currentUser ?? null) !== null)
            <a href="/avatar" @if(($currentPath ?? '') === '/avatar') class="current" aria-current="page" @endif>Avatar</a>
            <span class="who">{{ $currentUser->displayName }}</span>
            <form method="post" action="/logout" class="inline">
                <input type="hidden" name="_token" value="{{ $csrfToken ?? '' }}">
                <button type="submit" class="link">Sign out</button>
            </form>
        @else
            <a href="/login" @if(($currentPath ?? '') === '/login') class="current" aria-current="page" @endif>Sign in</a>
        @endif
    </nav>
</header>

<main id="content">
    @yield('content')
</main>

<footer class="site-footer">
    <p>Served by <strong>{{ $sapi }}</strong> on PHP {{ $phpVersion }}.</p>
    <p>@if($hasDocs)<a href="/docs/">Documentation</a> · @endif<a href="/health">Health</a></p>
</footer>
</body>
</html>
