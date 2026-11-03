@extends('auth-kit/layout')

@section('content')
<div class="prose">
    <h1>Create an account</h1>
    <p>Verify your email before signing in. Your account starts without administrator privileges.</p>
    <form method="post" action="/register" class="card">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <label>Display name <input type="text" name="name" required maxlength="100" autocomplete="nickname"></label>
        <label>Email <input type="email" name="email" required maxlength="191" autocomplete="email"></label>
        <label>Password <input type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password"></label>
        <label>Confirm password <input type="password" name="password_confirmation" required minlength="12" maxlength="72" autocomplete="new-password"></label>
        <button type="submit">Send verification email</button>
    </form>
    <p class="note">Verification links expire after thirty minutes. Submit again to request a new email.</p>
</div>
@endsection
