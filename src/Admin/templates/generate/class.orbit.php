@extends('layout')

@section('content')
@if($result !== null)
    <div class="result">
        <p class="banner banner-ok">Created <code>{{ $result->path }}</code></p>

        <p><strong>{{ $result->className }}</strong> — {{ $result->lifetime->describe() }}</p>

        @if($result->registrationSnippet !== null)
            <p class="muted">Add to app/bootstrap.php:</p>
            <pre><code>use {{ $result->className }};
{{ $result->registrationSnippet }}</code></pre>
        @endif

        <p class="muted">Inject it where you need it:</p>
        <pre><code>use {{ $result->className }};
{{ $result->injectionSnippet }}</code></pre>
    </div>
@endif

<div class="panel panel-form">
    <form method="post" action="/generate/class" class="generator-form">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">

        <label>
            Name
            <input type="text" name="name" value="{{ $old['name'] }}" placeholder="Notes/NoteRepository" required autofocus>
        </label>
        <p class="field-hint">StudlyCase, optionally nested: <code>Clock</code>, <code>Notes/NoteRepository</code>.</p>

        <fieldset>
            <legend>Lifetime</legend>
            @foreach($lifetimes as $lifetime)
                <label class="radio">
                    <input type="radio" name="lifetime" value="{{ $lifetime->value }}" @if($old['lifetime'] === $lifetime->value) checked @endif>
                    {{ $lifetime->value }} — {{ $lifetime->describe() }}
                </label>
            @endforeach
        </fieldset>

        <label class="checkbox">
            <input type="checkbox" name="force" value="1">
            Overwrite if it already exists
        </label>

        <button type="submit" class="button-primary">Generate class</button>
    </form>
</div>
@endsection
