@extends('layout')

@section('content')
<div class="panel panel-table">
    <div class="scroller">
    <table>
    <thead><tr><th>Method</th><th>Pattern</th><th>Name</th></tr></thead>
    <tbody>
    @foreach($rows as $row)
        <tr>
            <td><span class="chip chip-method">{{ $row['method'] }}</span></td>
            <td><code>{{ $row['pattern'] }}</code></td>
            <td class="muted">{{ $row['name'] }}</td>
        </tr>
    @endforeach
    </tbody>
    </table>
    </div>

    @if(count($rows) === 0)
        <p class="empty-state">No routes found in app/routes.php.</p>
    @endif
</div>
@endsection
