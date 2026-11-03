@extends('layout')

@section('content')
<div class="prose">
    <h1>Confirm sign-in</h1>
    <p>Continue only if you requested this link for your own account. Opening the link alone does not sign you in.</p>
    <form method="post" action="/magic/confirm" class="card">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <button type="submit">Continue signing in</button>
    </form>
    <p><a href="/login">Cancel and return to sign in</a></p>
</div>
@endsection
