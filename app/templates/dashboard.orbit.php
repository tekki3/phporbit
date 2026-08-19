@extends('layout')

@section('content')
    {# The verdict is the page's headline, not a footnote to a table. #}
    <section class="hero {{ $allPassed ? 'hero-pass' : 'hero-fail' }}">
        <p class="eyebrow">Live self-check</p>

        <h1>
            @if($allPassed)
                All {{ $totalCount }} checks passed
            @else
                {{ $passedCount }} of {{ $totalCount }} checks passed
            @endif
        </h1>

        <p class="hero-lede">
            Every check below runs the real component on this request — a query is executed,
            a template is rendered, a payload is escaped. Nothing here asserts a constant.
        </p>

        {# A real <progress>: it carries its own value and maximum, so the width
           comes from the element rather than an inline style, and assistive
           technology reads it without an aria-label describing a <div>. #}
        <progress class="meter" value="{{ $passedCount }}" max="{{ $totalCount }}">
            {{ $passedCount }} of {{ $totalCount }} checks passing
        </progress>
    </section>

    {# The request counter is the interesting one: it is what distinguishes a
       long-lived worker from a per-request SAPI at a glance. #}
    <section class="tiles">
        <div class="tile {{ $longLived ? 'tile-accent' : '' }}">
            <span class="tile-label">Requests this process</span>
            <span class="tile-value">{{ $workerRequests }}</span>
            <span class="tile-note">{{ $longLived ? 'long-lived worker' : 'first request' }}</span>
        </div>
        <div class="tile">
            <span class="tile-label">SAPI</span>
            <span class="tile-value tile-value-text">{{ $sapi }}</span>
            <span class="tile-note">PHP {{ $phpVersion }}</span>
        </div>
        <div class="tile">
            <span class="tile-label">Process uptime</span>
            <span class="tile-value">{{ $workerUptime }}<span class="tile-unit">s</span></span>
            <span class="tile-note">since boot</span>
        </div>
        <div class="tile">
            <span class="tile-label">Notes stored</span>
            <span class="tile-value">{{ $noteCount }}</span>
            <span class="tile-note">in SQLite</span>
        </div>
    </section>

    @foreach($checkGroups as $groupName => $groupChecks)
        <section class="group">
            <h2 class="group-title">{{ $groupName }}</h2>

            <ul class="checks">
                @foreach($groupChecks as $check)
                    <li class="check {{ $check->passed ? 'check-pass' : 'check-fail' }}">
                        <span class="chip">
                            <span class="chip-dot" aria-hidden="true"></span>
                            {{ $check->passed ? 'PASS' : 'FAIL' }}
                        </span>
                        <span class="check-name">{{ $check->name }}</span>
                        <span class="check-detail">{{ $check->detail }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach

    <section class="group">
        <h2 class="group-title">What the request counter proves</h2>

        <div class="card">
            @if($longLived)
                <p>
                    This process has served <strong>{{ $workerRequests }} requests</strong>, so it is a
                    long-lived worker — the same process model FrankenPHP uses. The request-scoped
                    probe above still reads 2, and that is the point: per-request state is
                    <strong>not</strong> surviving between requests even though the process does.
                </p>
            @else
                <p>
                    This is the first request this process has served. Reload the page: under
                    <code>orbit serve</code> or FrankenPHP the counter climbs, because the process
                    stays alive between requests. Under nginx or Apache it stays at 1, because the
                    process is torn down after each response.
                </p>
            @endif

            <p class="muted">
                A state leak is invisible on a per-request SAPI and a security bug on a worker,
                which is why the framework assumes the worker model everywhere.
            </p>
        </div>
    </section>
@endsection
