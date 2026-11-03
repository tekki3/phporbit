@extends('layout')

@section('content')
<div class="prose">
    <h1>{{ $title }}</h1>
    @if($notice)<p class="verdict verdict-pass" role="status">{{ $notice }}</p>@endif
    @if($error)<p class="verdict verdict-fail" role="alert">{{ $error }}</p>@endif
    <p>@if($magic)Receive a one-use sign-in link. Your authenticator is still required if enabled.@else Receive a one-use link to choose a new password. This does not disable your authenticator.@endif</p>
    <form method="post" action="@if($magic)/magic@else/password/forgot@endif" class="card">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <label>Email <input type="email" name="email" required autocomplete="email" maxlength="191"></label>
        <button type="submit">@if($magic)Email sign-in link@else Email reset link@endif</button>
    </form>
    <p class="note">Links expire after fifteen minutes. Email delivery must be configured by the site operator.</p>
    <p><a href="/login">Back to sign in</a></p>
</div>
@endsection
