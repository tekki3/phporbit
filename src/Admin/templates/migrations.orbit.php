@extends('layout')

@section('content')
<div class="actions">
    <form method="post" action="/migrations/run">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        @if($pendingCount > 0)
            <button type="submit" class="button-primary">
                Run {{ $pendingCount }} pending migration@if($pendingCount !== 1)s@endif
            </button>
        @else
            <button type="submit" class="button-done" disabled>
                <svg class="btn-icon"><use href="#icon-check"/></svg>
                Up to date
            </button>
        @endif
    </form>

    <form method="post" action="/migrations/rollback" class="inline-form">
        <input type="hidden" name="_token" value="{{ $csrfToken }}">
        <label class="inline-field">
            Batches
            <input type="number" name="batches" value="1" min="1">
        </label>
        <button type="submit" class="button-danger">Roll back</button>
    </form>
</div>

<div class="panel panel-table">
    <div class="scroller">
    <table>
    <thead><tr><th>Migration</th><th>Status</th><th>Batch</th></tr></thead>
    <tbody>
    @foreach($rows as $row)
        <tr>
            <td><code>{{ $row['name'] }}</code></td>
            <td>
                @if($row['applied'])
                    <span class="chip chip-ok"><span class="chip-dot"></span>applied</span>
                @else
                    <span class="chip chip-warn"><span class="chip-dot"></span>pending</span>
                @endif
            </td>
            <td>{{ $row['batch'] ?? '—' }}</td>
        </tr>
    @endforeach
    </tbody>
    </table>
    </div>

    @if(count($rows) === 0)
        <p class="empty-state">No migration files in database/migrations.</p>
    @endif
</div>
@endsection
