@extends('layout')

@section('content')
    <h1>It works</h1>

    <p class="lede">
        This page came from <code>app/templates/welcome.orbit.php</code>, rendered by
        <code>App\Controllers\WelcomeController</code>, reached through
        <code>app/routes.php</code>.
    </p>

    <div class="card">
        <h2>Next steps</h2>

        <ul>
            <li>Add a route in <code>app/routes.php</code>.</li>
            <li>Add a controller in <code>app/src/Controllers/</code> — constructor
                dependencies are autowired per request.</li>
            <li>Add a migration in <code>database/migrations/</code>, then
                <code>./orbit migrate</code>.</li>
            <li>Run <code>./orbit routes</code> to see the compiled table.</li>
        </ul>
    </div>

    <div class="card">
        <h2>Escaping is the default</h2>

        <p>
            <code>@{{ $value }}</code> escapes; showing raw markup needs the deliberately
            loud <code>@{!! $value !!}</code>. The safe form is the shorter one, which is
            the whole point.
        </p>

        <p class="muted">
            Both are printed literally here because of the <code>@</code> prefix — that
            is how a template shows template syntax, or leaves
            <code>@{{ handlebars }}</code> alone for a client-side framework.
        </p>
    </div>
@endsection
