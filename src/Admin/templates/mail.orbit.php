@extends('layout')

@section('content')
@if(!$available)
    <p class="banner banner-error">
        The <code>mail_log</code> table does not exist yet.
        <a href="/migrations">Run pending migrations</a> to enable this page.
    </p>
@else
    <div class="actions">
        <div class="filters">
            <a href="/mail" @if($status === null) class="current" @endif>All</a>
            <a href="/mail?status=sent" @if($status?->value === 'sent') class="current" @endif>Sent</a>
            <a href="/mail?status=failed" @if($status?->value === 'failed') class="current" @endif>Failed</a>
        </div>

        <form method="post" action="/mail/resend-failed">
            <input type="hidden" name="_token" value="{{ $csrfToken }}">
            <button type="submit" class="button-primary">Resend all failed</button>
        </form>
    </div>

    <div class="panel panel-table">
        <div class="scroller">
        <table>
        <thead><tr><th>ID</th><th>Status</th><th>Attempts</th><th>To</th><th>Subject</th><th>Updated</th><th></th></tr></thead>
        <tbody>
        @foreach($entries as $entry)
            <tr>
                <td><code>#{{ $entry->id }}</code></td>
                <td>
                    @if($entry->status->value === 'sent')
                        <span class="chip chip-ok"><span class="chip-dot"></span>sent</span>
                    @else
                        <span class="chip chip-fail"><span class="chip-dot"></span>failed</span>
                    @endif
                </td>
                <td>{{ $entry->attempts }}</td>
                <td>
                    @foreach($entry->to as $address)
                        <div>{{ $address->envelope() }}</div>
                    @endforeach
                </td>
                <td>{{ $entry->subjectLine }}</td>
                <td><time>{{ $entry->updatedAt }}</time></td>
                <td>
                    @if($entry->status->value === 'failed')
                        <form method="post" action="/mail/{{ $entry->id }}/resend">
                            <input type="hidden" name="_token" value="{{ $csrfToken }}">
                            <button type="submit" class="link">Resend</button>
                        </form>
                    @endif
                </td>
            </tr>
            @if($entry->error !== null)
                <tr class="row-detail">
                    <td></td>
                    <td colspan="6" class="muted">{{ $entry->error }}</td>
                </tr>
            @endif
        @endforeach
        </tbody>
        </table>
        </div>

        @if(count($entries) === 0)
            <p class="empty-state">No mail logged yet.</p>
        @endif
    </div>
@endif
@endsection
