@extends('layout')

@section('content')
<p class="tile-grid-intro">
    Each one shows the one or two lines still left to paste by hand — a
    route, a registration, an injection. None of these edit
    <code>app/routes.php</code> or <code>app/bootstrap.php</code> for you:
    rewriting a file you own means parsing and re-emitting your code, and
    getting that subtly wrong is worse than leaving a line to add yourself.
</p>

<div class="tiles">
    <a class="tile" href="/generate/class">
        <span class="tile-label">Class</span>
        <span class="tile-value tile-value-text">make:class</span>
        <span class="tile-note">A repository, a service, a value object — autowired, scoped or a singleton.</span>
    </a>

    <a class="tile" href="/generate/controller">
        <span class="tile-label">Controller</span>
        <span class="tile-value tile-value-text">make:controller</span>
        <span class="tile-note">One class per route, optionally with a template.</span>
    </a>

    <a class="tile" href="/generate/form">
        <span class="tile-label">Form</span>
        <span class="tile-value tile-value-text">make:form</span>
        <span class="tile-note">A validated form, protections included, optionally with its two pages.</span>
    </a>

    <a class="tile" href="/generate/middleware">
        <span class="tile-label">Middleware</span>
        <span class="tile-value tile-value-text">make:middleware</span>
        <span class="tile-note">A pass-through layer, ready to fill in.</span>
    </a>

    <a class="tile" href="/generate/migration">
        <span class="tile-label">Migration</span>
        <span class="tile-value tile-value-text">make:migration</span>
        <span class="tile-note">Schema shaped from the name — create, alter, or blank.</span>
    </a>
</div>
@endsection
