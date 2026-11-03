@extends('auth-kit/layout')

@section('content')
<div class="prose">
    <h1>Your account</h1>
    <p>Signed in as {{ $currentUser->email }}.</p>
    <form method="post" action="/account" class="card">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <label>Display name <input type="text" name="name" value="{{ $currentUser->displayName }}" required maxlength="100" autocomplete="nickname"></label>
        <button type="submit">Save profile</button>
    </form>
    <p>Your verified email is read-only. <a href="/account/security">Manage your authenticator and linked accounts</a>, or <a href="/password/forgot">request a password reset</a>.</p>
</div>
@endsection
