@extends('layout')

@section('content')
<div class="prose">
    <h1>Notes</h1>

    @if($flash)
        <p class="verdict verdict-pass">{{ $flash }}</p>
    @endif

    @if($error)
        <p class="verdict verdict-fail">{{ $error }}</p>
    @endif

    <p class="note">
        This form exercises CSRF protection, validation, prepared statements and
        POST-redirect-GET together. Submitting without the hidden token below returns
        403 — try it with <code>curl -X POST http://127.0.0.1:8080/notes</code>.
    </p>

    <form method="post" action="/notes" class="card">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">

        <label>
            Title
            <input type="text" name="title" maxlength="80" value="{{ $oldTitle }}" required>
        </label>

        <label>
            Body
            <textarea name="body" rows="3" maxlength="2000" required>{{ $oldBody }}</textarea>
        </label>

        <button type="submit">Save note</button>
    </form>

    @if(count($notes) === 0)
        <p class="note">No notes yet. Add one above.</p>
    @else
        <ul class="notes">
            @foreach($notes as $note)
                <li class="card">
                    <h3>{{ $note->title }}</h3>
                    <p>{{ $note->body }}</p>
                    <footer>
                        <time>{{ $note->createdAt }}</time>
                        <form method="post" action="/notes/{{ $note->id }}/delete">
                            <input type="hidden" name="_token" value="{{ $csrfToken }}">
                            <button type="submit" class="link">Delete</button>
                        </form>
                    </footer>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
