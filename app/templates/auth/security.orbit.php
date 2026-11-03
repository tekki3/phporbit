@extends('layout')

@section('content')
<div class="prose">
    <h1>Account security</h1>
    @if($notice)<p class="verdict verdict-pass" role="status">{{ $notice }}</p>@endif
    @if($error)<p class="verdict verdict-fail" role="alert">{{ $error }}</p>@endif
    <h2>Authenticator</h2>
    @if($enabled)
        <p>Two-step verification is on. Password, email-link and external sign-ins all require an authenticator or recovery code.</p>
        <p>To replace a lost authenticator, disable it using a recovery code, then enroll your new authenticator. New enrollment creates ten new recovery codes.</p>
        <form method="post" action="/account/mfa/disable" class="card">
            <input type="hidden" name="_token" value="{{ $csrfToken }}">
            <label>Current password <input type="password" name="password" required autocomplete="current-password"></label>
            <label>Authenticator or recovery code <input type="text" name="code" required maxlength="20" autocomplete="one-time-code"></label>
            <button type="submit">Disable authenticator</button>
        </form>
    @else
        <p>Add a second step with an authenticator app such as Microsoft Authenticator, Google Authenticator or 1Password.</p>
        <form method="post" action="/account/mfa/enroll" class="card">
            <input type="hidden" name="_token" value="{{ $csrfToken }}">
            <label>Current password <input type="password" name="password" required autocomplete="current-password"></label>
            <button type="submit">Set up authenticator</button>
        </form>
    @endif
    <h2>External sign-in</h2>
    <p>Link accounts here before using them to sign in. Matching email addresses never link accounts automatically. Confirm your password and, if enabled, an unused second-factor code for each change.</p>
    @foreach($providers as $provider)
        <form method="post" action="/account/link/{{ $provider }}" class="card">
            <input type="hidden" name="_token" value="{{ $csrfToken }}">
            <h3>@if($provider === 'google')Google@else Microsoft Entra ID@endif</h3>
            <label>Current password <input type="password" name="password" required autocomplete="current-password"></label>
            @if($enabled)<label>Authenticator or recovery code <input type="text" name="code" required maxlength="20" autocomplete="one-time-code"></label>@endif
            <button type="submit">Link @if($provider === 'google')Google@else Microsoft Entra ID@endif account</button>
        </form>
    @endforeach
    @if(!$providers)<p class="note">No external providers are configured.</p>@endif
    @foreach($linked as $account)
        <form method="post" action="/account/unlink/{{ $account['id'] }}" class="card">
            <input type="hidden" name="_token" value="{{ $csrfToken }}">
            <h3>Linked {{ $account['provider'] }} account</h3>
            <p class="note auth-identity">Issuer: {{ $account['issuer'] }}<br>Subject: {{ $account['subject'] }}</p>
            <label>Current password <input type="password" name="password" required autocomplete="current-password"></label>
            @if($enabled)<label>Authenticator or recovery code <input type="text" name="code" required maxlength="20" autocomplete="one-time-code"></label>@endif
            <button type="submit">Unlink account</button>
        </form>
    @endforeach
</div>
@endsection
