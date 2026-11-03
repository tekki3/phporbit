@extends('auth-kit/layout')

@section('content')
<div class="prose">
    <h1>Verify your email</h1>
    <p>Confirm only if you registered this account yourself. Opening the link alone does not create an account or sign you in.</p>
    <form method="post" action="/register/verify" class="card">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <button type="submit">Verify email and create account</button>
    </form>
    <p><a href="/register">Request a new verification email</a></p>
</div>
@endsection
