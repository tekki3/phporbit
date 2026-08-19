@extends('layout')

@section('content')
@if($result !== null)
    <div class="result">
        <p class="banner banner-ok">Created <code>{{ $result->formPath }}</code></p>

        <p><strong>{{ $result->className }}</strong> — posts to <code>{{ $result->action }}</code>, fields: {{ implode(', ', $result->fieldNames) }}</p>

        @foreach($result->controllerPaths as $path)
            <p class="muted">Created <code>{{ $path }}</code></p>
        @endforeach
        @if($result->templatePath !== null)
            <p class="muted">Created <code>{{ $result->templatePath }}</code></p>
        @endif

        @if(count($result->routeSnippets) > 0)
            <p class="muted">Add to app/routes.php:</p>
            {# Joined in the controller, not looped here: @foreach does not
               preserve the blank line between iterations that a <pre> block
               needs to read as separate lines. #}
            <pre><code>{{ $routesBlock }}</code></pre>
        @else
            <p class="muted">Build it from a controller:</p>
            <pre><code>use {{ $result->className }};
{{ $result->injectionSnippet }}</code></pre>
        @endif
    </div>
@endif

<div class="panel panel-form">
    <form method="post" action="/generate/form" class="generator-form">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">

        <label>
            Name
            <input type="text" name="name" value="{{ $old['name'] }}" placeholder="Contact" required autofocus>
        </label>
        <p class="field-hint">StudlyCase, optionally nested: <code>Contact</code>, <code>Admin/Invite</code>. The <code>Form</code> suffix is added once.</p>

        <label>
            Fields
            <input type="text" name="fields" value="{{ $old['fields'] }}" placeholder="name:text,email:email,message:textarea">
        </label>
        <p class="field-hint"><code>name:type</code>, comma-separated. Available types: <code>{{ $availableTypes }}</code>.</p>

        <fieldset>
            <legend>Protections &amp; output</legend>
            <label class="checkbox">
                <input type="checkbox" name="honeypot" value="1" @if($old['honeypot']) checked @endif>
                Honeypot — a decoy field and a signed clock, on by default
            </label>

            <label class="checkbox">
                <input type="checkbox" name="captcha" value="1" @if($old['captcha']) checked @endif>
                Also add a math captcha
            </label>

            <label class="checkbox">
                <input type="checkbox" name="controllers" value="1" @if($old['controllers']) checked @endif>
                Also write the two controllers and the template
            </label>

            <label class="checkbox">
                <input type="checkbox" name="force" value="1">
                Overwrite existing files
            </label>
        </fieldset>

        <button type="submit" class="button-primary">Generate form</button>
    </form>
</div>
@endsection
