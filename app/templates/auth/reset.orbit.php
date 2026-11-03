@extends('layout')

@section('content')
<div class="prose">
    <h1>Choose a new password</h1>
    @if($error)<p class="verdict verdict-fail" role="alert">{{ $error }}</p>@endif
    <p>Use a unique password between 12 and 72 bytes. All existing sessions and email links will be revoked.</p>
    <form method="post" action="/password/reset" class="card">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <label>New password <input type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password"></label>
        <label>Confirm new password <input type="password" name="password_confirmation" required minlength="12" maxlength="72" autocomplete="new-password"></label>
        <button type="submit">Change password</button>
    </form>
    <p><a href="/password/forgot">Request a new reset link</a></p>
</div>
@endsection
