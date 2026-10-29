@extends('layout')

@section('content')
@if($result !== null)
    <div class="result">
        <p class="banner banner-ok">Created <code>{{ $result->path }}</code></p>
        <p><code>{{ $result->className }}</code> uses table <code>{{ $result->table }}</code> and has {{ count($result->fieldNames) }} fields.</p>
        <p class="muted">Add this once in <code>app/bootstrap.php</code>, after creating the database connection:</p>
        <pre><code>use PhpOrbit\Database\Model;
Model::useConnection($database);</code></pre>
        <p class="muted">Then import the model: <code>{{ $result->importSnippet }}</code></p>
    </div>
@endif

<div class="panel panel-form">
    <form method="post" action="/generate/model" class="generator-form">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">

        <label>
            Name
            <input type="text" name="name" value="{{ $old['name'] }}" placeholder="Note or Blog/Post" required autofocus>
        </label>
        <p class="field-hint">Creates a model under <code>App\Models</code>; nested names are allowed.</p>

        <label>
            Table (optional)
            <input type="text" name="table" value="{{ $old['table'] }}" placeholder="Inferred from the model name">
        </label>

        <label>
            Fields (optional)
            <input type="text" name="fields" value="{{ $old['fields'] }}" placeholder="title:string,views:int">
        </label>
        <p class="field-hint">Use <code>name:type</code> entries, comma-separated. Types include string, int, float, bool, and nullable forms such as <code>?string</code>.</p>

        <label class="checkbox">
            <input type="checkbox" name="force" value="1">
            Overwrite if it already exists
        </label>

        <button type="submit" class="button-primary">Generate model</button>
    </form>
</div>
@endsection
