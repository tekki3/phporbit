@extends('layout')

@section('content')
@if($result !== null)
    <div class="result">
        <p class="banner banner-ok">Created <code>{{ $result->controllerPath }}</code>@if($result->templatePath !== null) and <code>{{ $result->templatePath }}</code>@endif</p>

        <p><strong>{{ $result->className }}</strong></p>

        <p class="muted">Add to app/routes.php:</p>
        <pre><code>{{ $result->importSnippet }}
{{ $result->routeSnippet }}</code></pre>
    </div>
@endif

<div class="panel panel-form">
    <form method="post" action="/generate/controller" class="generator-form">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">

        <label>
            Name
            <input type="text" name="name" value="{{ $old['name'] }}" placeholder="Admin/Users" required autofocus>
        </label>
        <p class="field-hint">StudlyCase, optionally nested: <code>Reports</code>, <code>Admin/Users</code>. The <code>Controller</code> suffix is added once, whether or not you type it.</p>

        <label class="checkbox">
            <input type="checkbox" name="withView" value="1" @if($old['withView']) checked @endif>
            Also write a template, and inject <code>TemplateEngine</code>
        </label>

        <label class="checkbox">
            <input type="checkbox" name="force" value="1">
            Overwrite if it already exists
        </label>

        <button type="submit" class="button-primary">Generate controller</button>
    </form>
</div>
@endsection
