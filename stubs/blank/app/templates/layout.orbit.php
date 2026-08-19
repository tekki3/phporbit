<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>

    <meta name="color-scheme" content="dark light">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<a class="skip" href="#content">Skip to content</a>

<header class="masthead">
    <a class="brand" href="/">{{ $appName ?? 'phporbit' }}</a>
    <nav>
        <a href="/">Home</a>
        <a href="/health">Health</a>
    </nav>
</header>

<main id="content">
    @yield('content')
</main>

<footer class="site-footer">
    <p>Served by <strong>{{ $sapi }}</strong> on PHP {{ $phpVersion }}.</p>
</footer>
</body>
</html>
