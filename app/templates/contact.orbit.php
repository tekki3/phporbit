@extends('layout')

@section('content')
<div class="prose">
    <h1>Contact</h1>

    <p>
        This form is generated from one declaration — the markup, the validation and
        the protections all come from the same fields. It is guarded by a honeypot
        (a decoy field and a signed clock) and an arithmetic question, neither of
        which needs JavaScript.
    </p>

    @if($sent ?? null)
        <p class="notice">{{ $sent }}</p>
    @endif

    @if($formError ?? null)
        <p class="error">{{ $formError }}</p>
    @endif

    {# The form escapes everything it renders; there is no method on it that
       emits raw HTML, so this is markup the application itself built. #}
    <div class="card">
        {!! $form !!}
    </div>

    <p class="muted">
        View the page source: the decoy sits in a <code>&lt;div hidden&gt;</code>, and
        the captcha's answer is encrypted rather than signed — a signed answer would
        be readable right here.
    </p>
</div>
@endsection
