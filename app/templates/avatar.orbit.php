@extends('layout')

@section('content')
<div class="prose">
    <h1>Avatar</h1>

    @if($notice)
        <p class="verdict verdict-pass">{{ $notice }}</p>
    @endif

    @if($error)
        <p class="verdict verdict-fail">{{ $error }}</p>
    @endif

    @if($avatar !== null)
        <p><img src="{{ $avatar }}" alt="Your avatar" class="avatar"></p>
    @else
        <p class="note">No avatar yet.</p>
    @endif

    <form method="post" action="/avatar" enctype="multipart/form-data" class="card">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">

        <label>
            Image file
            <input type="file" name="avatar" accept="image/*" required>
        </label>

        <button type="submit">Upload</button>
    </form>

    <p class="note">
        Accepted types: {{ $allowed }}, up to {{ $maxBytes }} bytes. The type is decided by
        sniffing the file's actual bytes, not by its name or the type the browser declared,
        and the stored filename is generated here — so renaming a script to
        <code>avatar.png</code> gets it rejected, not stored. SVG is refused on purpose:
        it can carry script, and serving one from this origin would be stored XSS.
    </p>
</div>
@endsection
