@extends('layout')

@section('content')
<div class="prose">
    <h1>Verify your identity</h1>
    @if($error)<p class="verdict verdict-fail" role="alert">{{ $error }}</p>@endif
    <p>Enter a fresh six-digit code from your authenticator, or one of your unused recovery codes. This sign-in expires after five minutes.</p>
    <form method="post" action="/mfa" class="card">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <label>Authenticator or recovery code <input type="text" name="code" required autocomplete="one-time-code" maxlength="20" spellcheck="false" autocapitalize="off"></label>
        <button type="submit">Verify and sign in</button>
    </form>
    <p><a href="/login">Start again</a></p>
</div>
@endsection
