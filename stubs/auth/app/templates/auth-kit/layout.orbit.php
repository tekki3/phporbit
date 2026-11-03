<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>
<a href="#content" class="skip">Skip to content</a>
<header class="masthead">
    <a href="/">Home</a>
    <nav aria-label="Account">
        @if($currentUser)
            <a href="/account">Profile</a>
            <a href="/account/security">Security</a>
            @if($currentUser->isAdmin)<a href="/account/users">Users</a>@endif
            <form method="post" action="/logout" class="inline">
                <input type="hidden" name="_token" value="{{ $csrfToken }}">
                <button type="submit" class="link">Sign out</button>
            </form>
        @else
            <a href="/login">Sign in</a>
            <a href="/register">Register</a>
        @endif
    </nav>
</header>
<main id="content">
    @if($notice)<p class="verdict verdict-pass" role="status">{{ $notice }}</p>@endif
    @if($error)<p class="verdict verdict-fail" role="alert">{{ $error }}</p>@endif
    @yield('content')
</main>
</body>
</html>
