@extends('auth-kit/layout')

@section('content')
<div class="prose">
    <h1>User management</h1>
    <p>Only verified accounts appear here. Confirm your password and, if enabled, an unused second-factor code for each change. You cannot block or demote yourself.</p>
    @foreach($users as $user)
        <section class="card">
            <h2>{{ $user['display_name'] }}</h2>
            <p class="auth-identity">{{ $user['email'] }}</p>
            <p>@if($user['is_admin'])Administrator@else Member@endif &middot; @if($user['blocked'])Blocked@else Active@endif</p>
            @if((int) $user['id'] !== $currentUser->id)
                <form method="post" action="/account/users/{{ $user['id'] }}">
                    <input type="hidden" name="_token" value="{{ $csrfToken }}">
                    <label>Action
                        <select name="action" required>
                            @if($user['blocked'])<option value="unblock">Unblock</option>@else<option value="block">Block</option>@endif
                            @if($user['is_admin'])<option value="demote">Remove administrator access</option>@else<option value="promote">Grant administrator access</option>@endif
                        </select>
                    </label>
                    <label>Your password <input type="password" name="password" required autocomplete="current-password"></label>
                    @if($enabled)<label>Authenticator or recovery code <input type="text" name="code" required maxlength="20" autocomplete="one-time-code"></label>@endif
                    <button type="submit">Apply user change</button>
                </form>
            @endif
        </section>
    @endforeach
    @if(!$users)<p>No users on this page.</p>@endif
    <nav aria-label="User pages">
        @if($pageNumber > 1)<a href="/account/users?page={{ $pageNumber - 1 }}">Previous page</a>@endif
        @if(count($users) === 25)<a href="/account/users?page={{ $pageNumber + 1 }}">Next page</a>@endif
    </nav>
</div>
@endsection
