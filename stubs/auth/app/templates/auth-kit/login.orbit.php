@extends('auth-kit/layout')

@section('content')
<div class="prose">
    <h1>Sign in</h1>
    <form method="post" action="/login" class="card">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <label>Email <input type="email" name="email" required maxlength="191" autocomplete="username"></label>
        <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
        <button type="submit">Sign in</button>
    </form>
    <p><a href="/register">Create an account</a> &middot; <a href="/password/forgot">Forgot your password?</a></p>
    <p><a href="/magic">Sign in with an email link</a></p>
    @foreach($providers as $provider)
        <form method="post" action="/auth/{{ $provider }}/start" class="card">
            <input type="hidden" name="_token" value="{{ $csrfToken }}">
            <button type="submit">Sign in with @if($provider === 'google')Google@else Microsoft Entra ID@endif</button>
        </form>
    @endforeach
</div>
@endsection
