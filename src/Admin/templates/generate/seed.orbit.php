@extends('layout')

@section('content')
@if($result !== null)
    <div class="result">
        <p class="banner banner-ok">Created <code>{{ $result }}</code></p>
        <p class="muted">Edit the seeder, then <a href="/seeders">run it</a>.</p>
    </div>
@endif

<div class="panel panel-form">
    <form method="post" action="/generate/seed" class="generator-form">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">

        <label>
            Name
            <input type="text" name="name" value="{{ $old['name'] }}" placeholder="0002_categories" required autofocus>
        </label>
        <p class="field-hint">Lowercase words and underscores, optionally prefixed with digits for ordering.</p>

        <label class="checkbox">
            <input type="checkbox" name="force" value="1">
            Overwrite if it already exists
        </label>

        <button type="submit" class="button-primary">Generate seeder</button>
    </form>
</div>
@endsection
