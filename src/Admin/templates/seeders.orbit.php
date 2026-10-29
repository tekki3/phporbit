@extends('layout')

@section('content')
<p class="muted">Seeders run every time and are not tracked. Make their database writes idempotent.</p>

<div class="actions">
    <form method="post" action="/seeders/run">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <button type="submit" class="button-primary">Run all seeders</button>
    </form>
    <a class="button" href="/generate/seed">Generate a seeder</a>
</div>

<div class="panel panel-table">
    <div class="scroller">
    <table>
    <thead><tr><th>Seeder</th><th>Action</th></tr></thead>
    <tbody>
    @foreach($seeders as $name)
        <tr>
            <td><code>{{ $name }}</code></td>
            <td>
                <form method="post" action="/seeders/run">
                    <input type="hidden" name="_token" value="{{ $csrfToken }}">
                    <input type="hidden" name="name" value="{{ $name }}">
                    <button type="submit" class="link">Run this seeder</button>
                </form>
            </td>
        </tr>
    @endforeach
    </tbody>
    </table>
    </div>

    @if(count($seeders) === 0)
        <p class="empty-state">No seeder files found. Generate one to get started.</p>
    @endif
</div>
@endsection
