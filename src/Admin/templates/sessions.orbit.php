@extends('layout')

@section('content')
<div class="tiles">
    <div class="tile">
        <span class="tile-label">Session files</span>
        <span class="tile-value">{{ $fileCount }}</span>
        <span class="tile-note">storage/sessions</span>
    </div>
</div>

<div class="panel">
    <p class="panel-lede">
        A cookie naming an unknown or expired session is never adopted — an
        expired file already cannot be read back, whether or not it is ever
        deleted. This only reclaims disk space.
    </p>

    <form method="post" action="/sessions/gc">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <button type="submit" class="button-primary">Remove expired sessions</button>
    </form>
</div>
@endsection
