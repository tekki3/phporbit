<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · phporbit admin</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark light">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
<a class="skip" href="#content">Skip to content</a>

{# One sprite, referenced by every <use> below — no JavaScript involved,
   browsers resolve #fragments in an inline <symbol> natively. Keeps each nav
   link to one line instead of a repeated <path> per icon. #}
<svg class="icon-sprite" aria-hidden="true">
    <symbol id="icon-overview" viewBox="0 0 24 24"><rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/></symbol>
    <symbol id="icon-migrations" viewBox="0 0 24 24"><path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 12l9 5 9-5"/><path d="M3 17l9 5 9-5"/></symbol>
    <symbol id="icon-mail" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 7l8.5 6 8.5-6"/></symbol>
    <symbol id="icon-routes" viewBox="0 0 24 24"><circle cx="6" cy="6.5" r="2.5"/><circle cx="18" cy="17.5" r="2.5"/><path d="M8 8c3.5 0 2.5 8.5 8 8.5"/></symbol>
    <symbol id="icon-sessions" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5v5l3.5 2"/></symbol>
    <symbol id="icon-storage" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="4.5" rx="1.2"/><path d="M5 8.7v9.3a2 2 0 002 2h10a2 2 0 002-2V8.7"/><path d="M10 13h4"/></symbol>
    <symbol id="icon-generate" viewBox="0 0 24 24"><path d="M12 2.5l1.9 5.6 5.6 1.9-5.6 1.9L12 17.5l-1.9-5.6-5.6-1.9 5.6-1.9L12 2.5z"/><path d="M19 15.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8.8-2.2z"/></symbol>
    <symbol id="icon-tools" viewBox="0 0 24 24"><path d="M14.5 6.5a3.7 3.7 0 10-5.2 5.2L4 17l3 3 5.3-5.3a3.7 3.7 0 005.2-5.2l-2.6 2.6-2-2 2.6-2.6z"/></symbol>
    <symbol id="icon-check" viewBox="0 0 24 24"><path d="M4 12.5l5.5 5.5L20 6.5"/></symbol>
</svg>

<div class="shell">
    <nav class="sidebar" aria-label="Admin sections">
        <a class="brand" href="/">
            <span class="brand-mark">◎</span>
            <span class="brand-name">phporbit<span>admin</span></span>
        </a>

        <div class="nav-section">
            <a class="nav-link" href="/" @if($currentPath === '/') aria-current="page" @endif>
                <svg class="nav-icon"><use href="#icon-overview"/></svg>
                Overview
            </a>
        </div>

        <div class="nav-section">
            <p class="nav-group">Manage</p>
            <a class="nav-link" href="/migrations" @if($currentPath === '/migrations') aria-current="page" @endif>
                <svg class="nav-icon"><use href="#icon-migrations"/></svg>
                Migrations
            </a>
            <a class="nav-link" href="/mail" @if($currentPath === '/mail') aria-current="page" @endif>
                <svg class="nav-icon"><use href="#icon-mail"/></svg>
                Mail
            </a>
            <a class="nav-link" href="/routes" @if($currentPath === '/routes') aria-current="page" @endif>
                <svg class="nav-icon"><use href="#icon-routes"/></svg>
                Routes
            </a>
            <a class="nav-link" href="/sessions" @if($currentPath === '/sessions') aria-current="page" @endif>
                <svg class="nav-icon"><use href="#icon-sessions"/></svg>
                Sessions
            </a>
            <a class="nav-link" href="/storage" @if($currentPath === '/storage') aria-current="page" @endif>
                <svg class="nav-icon"><use href="#icon-storage"/></svg>
                Storage
            </a>
        </div>

        <div class="nav-section">
            <a class="nav-link nav-group-link" href="/generate" @if($currentPath === '/generate') aria-current="page" @endif>
                <svg class="nav-icon"><use href="#icon-generate"/></svg>
                Generate
            </a>
            <div class="nav-sublist">
                <a class="nav-sublink" href="/generate/class" @if($currentPath === '/generate/class') aria-current="page" @endif>Class</a>
                <a class="nav-sublink" href="/generate/controller" @if($currentPath === '/generate/controller') aria-current="page" @endif>Controller</a>
                <a class="nav-sublink" href="/generate/form" @if($currentPath === '/generate/form') aria-current="page" @endif>Form</a>
                <a class="nav-sublink" href="/generate/middleware" @if($currentPath === '/generate/middleware') aria-current="page" @endif>Middleware</a>
                <a class="nav-sublink" href="/generate/migration" @if($currentPath === '/generate/migration') aria-current="page" @endif>Migration</a>
            </div>
        </div>

        <div class="nav-section nav-section-tools">
            <a class="nav-link" href="/tools" @if($currentPath === '/tools') aria-current="page" @endif>
                <svg class="nav-icon"><use href="#icon-tools"/></svg>
                Tools
            </a>
        </div>

        <p class="sidebar-note">Local only — no login. Never bind this beyond 127.0.0.1.</p>
    </nav>

    <main id="content">
        <header class="topbar">
            <h1>{{ $title }}</h1>
            @if($subtitle ?? null)
                <p class="subtitle">{{ $subtitle }}</p>
            @endif
        </header>

        @if($flash ?? null)
            <p class="banner banner-ok">{{ $flash }}</p>
        @endif

        @if($error ?? null)
            <p class="banner banner-error">{{ $error }}</p>
        @endif

        @yield('content')
    </main>
</div>
</body>
</html>
