@extends('layout')

@section('content')
@if($result !== null)
    <div class="result">
        <p class="banner banner-ok">Created <code>{{ $result->path }}</code></p>

        <p class="muted">Edit it, then <a href="/migrations">run pending migrations</a>.</p>
    </div>
@endif

<div class="panel panel-form">
    <form method="post" action="/generate/migration" class="generator-form">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">

        <label>
            Name
            <input type="text" name="name" value="{{ $old['name'] }}" placeholder="create_articles_table" required autofocus>
        </label>
        <p class="field-hint">Words, not a path: <code>create_articles_table</code>, <code>add_slug_to_articles</code>.</p>

        <label>
            Table (optional)
            <input type="text" name="table" value="{{ $old['table'] }}" placeholder="Inferred from the name if left blank">
        </label>

        <label class="checkbox">
            <input type="checkbox" name="sequential" value="1" @if($old['sequential']) checked @endif>
            Number it <code>0001</code>, <code>0002</code> … instead of by timestamp
        </label>

        <label class="checkbox">
            <input type="checkbox" name="force" value="1">
            Overwrite if it already exists
        </label>

        <button type="submit" class="button-primary">Generate migration</button>
    </form>
</div>
@endsection
