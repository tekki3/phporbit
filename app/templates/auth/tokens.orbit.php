@extends('layout')

@section('content')
<div class="prose">
    <h1>API tokens</h1>
    @if($notice)<p class="verdict verdict-pass" role="status">{{ $notice }}</p>@endif
    @if($error)<p class="verdict verdict-fail" role="alert">{{ $error }}</p>@endif
    <p>Personal tokens can read your API profile and expire after thirty days. Treat them like passwords. <a href="/api/docs">Open the API reference</a>.</p>
    @if($issuedToken)
        <section aria-label="New personal token">
            <h2>Copy your token now</h2>
            <p>This value is shown only in this response. It is not stored in your session or the mail log.</p>
            <p class="auth-secret"><code>{{ $issuedToken }}</code></p>
        </section>
    @endif
    <h2>Create a personal token</h2>
    <form method="post" action="/account/tokens">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <label>Name <input type="text" name="name" required maxlength="100" placeholder="Local development"></label>
        <label>Current password <input type="password" name="password" required autocomplete="current-password"></label>
        @if($enabled)<label>Authenticator or recovery code <input type="text" name="code" required maxlength="20" autocomplete="one-time-code"></label>@endif
        <button type="submit">Create token</button>
    </form>
    <h2>Unexpired tokens</h2>
    <p>Up to 100 unexpired personal and JWT records are listed. Some may already be invalid after password resets, MFA changes or account security revocation. Revoke records you no longer need.</p>
    @foreach($tokens as $token)
        <section>
            <h3>{{ $token['token_name'] }}</h3>
            <p>Type: {{ $token['token_kind'] }} · Expires: {{ gmdate('Y-m-d H:i:s', (int) $token['expires_at']) }} UTC</p>
            <form method="post" action="/account/tokens/{{ $token['token_id'] }}/revoke">
                <input type="hidden" name="_token" value="{{ $csrfToken }}">
                <label>Current password <input type="password" name="password" required autocomplete="current-password"></label>
                @if($enabled)<label>Authenticator or recovery code <input type="text" name="code" required maxlength="20" autocomplete="one-time-code"></label>@endif
                <button type="submit">Revoke {{ $token['token_name'] }}</button>
            </form>
        </section>
    @endforeach
    @if(!$tokens)<p>No active tokens.</p>@endif
</div>
@endsection
