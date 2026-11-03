@extends('layout')

@section('content')
<p>Generate editable login, registration with mandatory email verification, TOTP/recovery codes, profile pages and administrator user management. Existing files are never overwritten.</p>
@if($error)<p class="verdict verdict-fail" role="alert">{{ $error }}</p>@endif
@if($files !== null)
    <h2>Authentication generated</h2>
    <p>New blank projects load <code>app/auth.php</code> automatically. In older projects, add this inside the boot callback after service bindings, before loading your routes:</p>
    <pre><code>{{ $wiring }}</code></pre>
    <p>Configure SMTP and APP_URL, run <code>php orbit migrate</code>, then visit <code>/register</code>. After verifying your email, run <code>php orbit auth:admin your@email.example</code> to appoint the first administrator.</p>
    <ul>@foreach($files as $file)<li><code>{{ $file }}</code></li>@endforeach</ul>
@else
    <form method="post" action="/generate/auth" class="generator-form">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <button type="submit">Generate authentication</button>
    </form>
@endif
@endsection
