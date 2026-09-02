@extends('layout')

@section('content')
<div class="prose">
    <h1>{{ $heading }}</h1>
    <p>{{ $message }}</p>

    @if($debugDetail !== null)
        <p class="note">Shown because <code>APP_DEBUG</code> is on — hidden in production.</p>
        <pre class="trace">{{ $debugDetail }}</pre>
    @endif

    <p class="note"><a href="/">Back to the self-check page</a></p>
</div>
@endsection
