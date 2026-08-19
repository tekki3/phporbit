@extends('layout')

@section('content')
<div class="prose">
    <h1>Hello, {{ $name }}</h1>

    <p class="note">
        The name above came straight from the URL path and was written with
        <code>@{{ $name }}</code>. Try
        <code>/hello/&lt;script&gt;alert(1)&lt;/script&gt;</code> and view the page source:
        the payload arrives as text because escaping is the default, not because this
        template remembered to ask for it. Writing <code>@{{</code> renders literal
        braces, which is how this sentence shows the syntax without invoking it.
    </p>
</div>
@endsection
