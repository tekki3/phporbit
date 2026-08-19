@extends('layout')

@section('content')
<div class="tiles">
    <div class="tile">
        <span class="tile-label">Compiled templates</span>
        <span class="tile-value">{{ $fileCount }}</span>
        <span class="tile-note">{{ $size }} on disk</span>
    </div>
</div>

<div class="panel">
    <p class="panel-lede">
        Safe at any time: a template recompiles the moment it finds no cached file
        waiting for it, so the very next render replaces whatever this deletes.
        Nothing else under <code>storage/</code> is touched.
    </p>

    <form method="post" action="/storage/clear">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <button type="submit" class="button-primary">Clear template cache</button>
    </form>
</div>
@endsection
