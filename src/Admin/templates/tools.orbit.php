@extends('layout')

@section('content')
<div class="tool-grid">
    <div class="panel">
        <h2>Generate an APP_KEY</h2>
        <p class="panel-lede">Prints a key rather than writing one — the same as <code>orbit key:generate</code>. Copy it into <code>.env</code> yourself; nothing here touches the file.</p>

        @if($generatedKey !== null)
            <div class="result">
                <pre><code>APP_KEY={{ $generatedKey }}</code></pre>
            </div>
        @endif

        <form method="post" action="/tools/key">
            <input type="hidden" name="_token" value="{{ $csrfToken }}">
            <button type="submit" class="button-primary">Generate a new key</button>
        </form>
    </div>

    <div class="panel">
        <h2>Send a test message</h2>
        <p class="panel-lede">
            Sends one real message through the configured driver
            (<code>MAIL_DRIVER={{ $mailDriver }}</code>) — the same as
            <code>orbit mail:test</code>. Logged like any other send, so a
            failure can be resent from <a href="/mail">Mail</a> once fixed.
        </p>

        @if($mailResult ?? null)
            <p class="banner banner-ok">{{ $mailResult }}</p>
        @endif

        <form method="post" action="/tools/mail-test" class="generator-form">
            <input type="hidden" name="_token" value="{{ $csrfToken }}">

            <label>
                To
                <input type="email" name="to" value="{{ $mailTo }}" placeholder="you@example.test" required>
            </label>

            <label>
                From (optional)
                <input type="email" name="from" value="{{ $mailFrom }}" placeholder="{{ $defaultFrom !== '' ? $defaultFrom : 'Uses MAIL_FROM_ADDRESS if left blank' }}">
            </label>

            <button type="submit" class="button-primary">Send test message</button>
        </form>
    </div>
</div>
@endsection
