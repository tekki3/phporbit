@extends('layout')

@section('content')
<div class="prose">
    <h1>Set up an authenticator</h1>
    <p>Add a time-based account to your authenticator app using this setup key. Keep it private.</p>
    <p><code class="auth-secret">{{ $secret }}</code></p>
    <p><a href="{{ $authenticatorUri }}">Open in an installed authenticator</a></p>
    <p>Enter the app's six-digit code and confirm your password to finish. This setup expires in ten minutes.</p>
    <form method="post" action="/account/mfa/enable" class="card">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <label>Current password <input type="password" name="password" required autocomplete="current-password"></label>
        <label>Authenticator code <input type="text" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"></label>
        <button type="submit">Enable authenticator</button>
    </form>
    <p><a href="/account/security">Cancel setup</a></p>
</div>
@endsection
