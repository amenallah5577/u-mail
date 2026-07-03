@extends('layouts.app')
@section('title', 'Account Credentials')
@section('content')
<section class="credentials-page">
    <div class="page-heading">
        <div><p class="eyebrow">OWNER ACCESS ONLY</p><h1>Account credentials</h1></div>
        <span class="page-count"><b>{{ $accountTotal }}</b> accounts</span>
    </div>
    <form class="account-finder credentials-finder" method="GET" action="{{ route('owner.credentials') }}">
        <label class="account-search">
            <x-icon name="search" />
            <span>
                <small>Find credentials</small>
                <input name="q" value="{{ $search }}" placeholder="Name, U-Mail address, or phone">
            </span>
        </label>
        <label class="account-filter">
            <small>Account type</small>
            <select name="role">
                <option value="">All account types</option>
                <option value="employee" @selected($role === 'employee')>Employees</option>
                <option value="admin" @selected($role === 'admin')>Administrators</option>
            </select>
        </label>
        <label class="account-filter">
            <small>Account status</small>
            <select name="status">
                <option value="">All statuses</option>
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="pending" @selected($status === 'pending')>Pending activation</option>
                <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                <option value="requested" @selected($status === 'requested')>Awaiting approval</option>
                <option value="email_verification" @selected($status === 'email_verification')>Awaiting email confirmation</option>
                <option value="rejected" @selected($status === 'rejected')>Rejected</option>
                <option value="deleted" @selected($status === 'deleted')>Deleted</option>
            </select>
        </label>
        <button class="finder-submit" type="submit"><x-icon name="search" /><span>Search</span></button>
        @if($search || $role || $status)
            <a class="finder-clear" href="{{ route('owner.credentials') }}"><x-icon name="close" /><span>Clear</span></a>
        @endif
    </form>
    <div class="finder-summary credentials-summary">
        <span><strong>{{ $accounts->total() }}</strong> {{ $search || $role || $status ? 'matching' : 'stored' }} {{ \Illuminate\Support\Str::plural('account', $accounts->total()) }}</span>
        @if($accountTotal !== $accounts->total())<small>{{ $accountTotal }} total accounts</small>@endif
    </div>
    <div class="credentials-grid">
        @forelse($accounts as $account)
            <article class="credential-card">
                <div class="credential-meta">
                    <small>{{ $account->role }}</small>
                    <span class="status {{ $account->trashed() ? 'inactive' : $account->status }}">{{ $account->trashed() ? 'deleted' : $account->status }}</span>
                </div>
                <h2>{{ $account->name }}</h2>
                <p>{{ $account->mailAddress() }}</p>
                <div class="credential-password">
                    <span>Password</span>
                    @if($revealedAccount?->id === $account->id)
                        <code>{{ $revealedAccount->credential?->password_encrypted ?? 'Not set yet' }}</code>
                    @else
                        <code>••••••••••••</code>
                        <form method="POST" action="{{ route('owner.credentials.reveal', ['user' => $account->id] + request()->only(['q', 'role', 'status', 'page'])) }}">@csrf<button class="soft-button">Reveal once</button></form>
                    @endif
                </div>
            </article>
        @empty
            <div class="empty-state account-empty"><x-icon name="search" /><h2>No matching accounts</h2><p>Try a name, U-Mail address, phone number, or fewer filters.</p><a class="soft-button" href="{{ route('owner.credentials') }}">Show all accounts</a></div>
        @endforelse
    </div>
    <div class="pagination">{{ $accounts->links() }}</div>
</section>
@endsection
