@extends('layout')

@section('content')
@if($result !== null)
    <div class="result">
        <p class="banner banner-ok">Created <code>{{ $result->path }}</code></p>

        <p><strong>{{ $result->className }}</strong></p>

        <p class="muted">Add to the $app->middleware(...) list in app/bootstrap.php:</p>
        <pre><code>{{ $result->importSnippet }}
{{ $result->registrationSnippet }}</code></pre>
    </div>
@endif

<div class="panel panel-form">
    <form method="post" action="/generate/middleware" class="generator-form">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">

        <label>
            Name
            <input type="text" name="name" value="{{ $old['name'] }}" placeholder="RequestId" required autofocus>
        </label>
        <p class="field-hint">StudlyCase, optionally nested: <code>RequestId</code>, <code>Admin/RequireApiKey</code>. The <code>Middleware</code> suffix is added once.</p>

        <label class="checkbox">
            <input type="checkbox" name="force" value="1">
            Overwrite if it already exists
        </label>

        <button type="submit" class="button-primary">Generate middleware</button>
    </form>
</div>

<p class="muted">Where in the <code>$app->middleware(...)</code> list this belongs is a decision only you can make — order there is meaning, not plumbing.</p>
@endsection
