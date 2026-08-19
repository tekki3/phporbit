@extends('layout')

@section('content')
<div class="tiles">
    <a class="tile @if($pendingMigrations > 0) tile-warn @endif" href="/migrations">
        <span class="tile-label">Migrations</span>
        <span class="tile-value">{{ $appliedMigrations }}<span class="tile-unit">applied</span></span>
        <span class="tile-note">
            @if($pendingMigrations > 0)
                {{ $pendingMigrations }} pending
            @else
                Up to date
            @endif
        </span>
    </a>

    <a class="tile @if($mailAvailable && $mailFailed > 0) tile-warn @endif" href="/mail">
        <span class="tile-label">Mail</span>
        @if($mailAvailable)
            <span class="tile-value">{{ $mailSent }}<span class="tile-unit">sent</span></span>
            <span class="tile-note">
                @if($mailFailed > 0)
                    {{ $mailFailed }} failed
                @else
                    None failed
                @endif
            </span>
        @else
            <span class="tile-value tile-value-text">Not migrated</span>
            <span class="tile-note">Run pending migrations to enable</span>
        @endif
    </a>

    <a class="tile" href="/routes">
        <span class="tile-label">Routes</span>
        <span class="tile-value">{{ $routeCount }}<span class="tile-unit">compiled</span></span>
        <span class="tile-note">From app/routes.php</span>
    </a>

    <a class="tile" href="/sessions">
        <span class="tile-label">Sessions</span>
        <span class="tile-value">{{ $sessionCount }}<span class="tile-unit">on disk</span></span>
        <span class="tile-note">storage/sessions</span>
    </a>

    <a class="tile" href="/storage">
        <span class="tile-label">Template cache</span>
        <span class="tile-value tile-value-text">Manage</span>
        <span class="tile-note">storage/cache/views</span>
    </a>

    <a class="tile" href="/generate">
        <span class="tile-label">Generate</span>
        <span class="tile-value tile-value-text">5 generators</span>
        <span class="tile-note">Class, controller, form, middleware, migration</span>
    </a>
</div>
@endsection
