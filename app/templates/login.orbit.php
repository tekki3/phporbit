@extends('layout')

@section('content')
<div class="prose">
    {# An auth screen is where identity matters most, so the mark gets room
       here rather than only in the masthead. Decorative: the heading below
       already says what this page is. #}
    <img class="signin-mark" src="/assets/brand/phporbit-mark.svg" alt="" width="64" height="64">

    <h1>Sign in</h1>

    @if($notice)
        <p class="verdict verdict-pass">{{ $notice }}</p>
    @endif

    @if($error)
        <p class="verdict verdict-fail">{{ $error }}</p>
    @endif

    <p class="note">
        Seeded account: <code>demo@example.test</code> / <code>correct-horse-battery</code>.
        Five failed attempts within fifteen minutes are throttled, and the error message
        never says whether an address is registered — that distinction is how login forms
        become account enumerators.
    </p>

    <form method="post" action="/login" class="card">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">

        <label>
            Email
            <input type="email" name="email" value="{{ $oldEmail }}" required autocomplete="username">
        </label>

        <label>
            Password
            <input type="password" name="password" required autocomplete="current-password">
        </label>

        <button type="submit">Sign in</button>
    </form>
    <p><a href="/password/forgot">Forgot your password?</a> &middot; <a href="/magic">Sign in with an email link</a></p>
    @foreach($providers as $provider)
        <form method="post" action="/auth/{{ $provider }}/start" class="card">
            <input type="hidden" name="_token" value="{{ $csrfToken }}">
            <button type="submit">Sign in with @if($provider === 'google')Google@else Microsoft Entra ID@endif</button>
        </form>
    @endforeach
    @if($providers)<p class="note">External accounts must first be linked from Account security after signing in with your password.</p>@endif
</div>
@endsection
