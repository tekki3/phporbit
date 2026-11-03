@extends('layout')

@section('content')
<div class="prose">
    <h1>Save your recovery codes</h1>
    <p class="verdict verdict-pass" role="status">Two-step verification is enabled.</p>
    <p>These codes are shown only now. Store them in your password manager or print this page and keep it somewhere safe. Each code works once in place of your authenticator.</p>
    <ul class="recovery-codes">
        @foreach($codes as $code)<li><code>{{ $code }}</code></li>@endforeach
    </ul>
    <p>If you lose both your authenticator and all recovery codes, email links and password resets will not restore access. Contact the site operator.</p>
    <p><a href="/account/security">I have saved my codes</a></p>
</div>
@endsection
