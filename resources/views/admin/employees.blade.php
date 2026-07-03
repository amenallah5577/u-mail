@extends('layouts.app')
@section('title', 'Employees')
@section('content')
<section class="admin-page" data-agent-context data-agent-context-type="admin_employees">
    <div class="page-heading"><div><p class="eyebrow">UTICA JENDOUBA ADMINISTRATION</p><h1>Employee accounts</h1></div><span class="page-count"><b>{{ $requests->count() }}</b> awaiting approval</span></div>
    <p class="security-confirm-note">Sensitive administrator actions require a password confirmation every five minutes. <a href="{{ route('password.confirm') }}">Confirm password</a></p>
    <div class="u-assist-bar admin-assist" aria-label="U-Assist administrator actions">
        <span><x-icon name="sparkles" /> U-Assist</span>
        <button type="button" data-agent-action data-agent-context-type="admin_employees" data-agent-prompt="Summarize the account requests waiting for approval and highlight visible risks.">Review requests</button>
        <button type="button" data-agent-action data-agent-context-type="admin_employees" data-agent-prompt="Prepare a safe administrator action plan for today. Do not approve, reject, delete, or reset anything automatically.">Admin action plan</button>
    </div>
    <div class="approval-grid">
        <div class="panel request-panel">
            <p class="eyebrow">ACCOUNT REQUESTS</p><h2>Approve new accounts</h2>
            <p class="panel-note">These requesters confirmed their contact email. Approval activates the generated U-Mail address and emails a temporary password.</p>
            @forelse($requests as $accountRequest)
                <article class="request-row">
                    <div>
                        <strong>{{ $accountRequest->name }}</strong>
                        <small class="public-address">{{ $accountRequest->mailAddress() }} · U-Mail address</small>
                        <small>Verified contact email hidden @if($accountRequest->phone) · {{ $accountRequest->phone }} @endif</small>
                    </div>
                    <span class="status pending">Awaiting approval</span>
                    <div class="request-actions">
                        <button class="soft-button" type="button" data-agent-action data-agent-context-type="account_request" data-agent-context-id="{{ $accountRequest->id }}" data-agent-prompt="Review this account request, compare visible public account data, explain any risk, and prepare approval or rejection wording.">U-Assist review</button>
                        <form method="POST" action="{{ route('admin.employees.approve', $accountRequest) }}">@csrf
                            <button class="soft-button">Approve and send details</button>
                        </form>
                        <form method="POST" action="{{ route('admin.employees.reject', $accountRequest) }}">@csrf
                            <button class="soft-button danger-button" data-confirm="Reject this account request?">Reject</button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="empty-state compact-empty"><h2>No requests waiting</h2><p>New account requests will appear here.</p></div>
            @endforelse
        </div>
        <div class="panel employee-panel">
            <p class="eyebrow">ACTIVE DIRECTORY</p><h2>Manage approved accounts</h2>
            <form class="account-finder" method="GET" action="{{ route('admin.employees') }}">
                <label class="account-search">
                    <x-icon name="search" />
                    <span>
                        <small>Find an account</small>
                        <input name="q" value="{{ $search }}" placeholder="Name, U-Mail address, or phone">
                    </span>
                </label>
                <label class="account-filter">
                    <small>Account type</small>
                    <select name="role">
                        <option value="">All account types</option>
                        <option value="employee" @selected($roleFilter === 'employee')>Employees</option>
                        <option value="admin" @selected($roleFilter === 'admin')>Administrators</option>
                    </select>
                </label>
                <label class="account-filter">
                    <small>Account status</small>
                    <select name="status">
                        <option value="">All statuses</option>
                        <option value="active" @selected($statusFilter === 'active')>Active</option>
                        <option value="pending" @selected($statusFilter === 'pending')>Pending activation</option>
                        <option value="inactive" @selected($statusFilter === 'inactive')>Inactive</option>
                    </select>
                </label>
                <button class="finder-submit" type="submit"><x-icon name="search" /><span>Search</span></button>
                @if($search || $roleFilter || $statusFilter)
                    <a class="finder-clear" href="{{ route('admin.employees') }}"><x-icon name="close" /><span>Clear</span></a>
                @endif
            </form>
            <div class="finder-summary">
                <span><strong>{{ $employees->total() }}</strong> {{ $search || $roleFilter || $statusFilter ? 'matching' : 'managed' }} {{ \Illuminate\Support\Str::plural('account', $employees->total()) }}</span>
                @if($employeeTotal !== $employees->total())<small>{{ $employeeTotal }} total approved accounts</small>@endif
            </div>
            @forelse($employees as $employee)
                <article class="employee-row">
                    <x-user-avatar :user="$employee" />
                    <div><strong>{{ $employee->name }}</strong><small class="public-address">{{ $employee->mailAddress() }} · {{ ucfirst($employee->role) }}</small><small>Private contact email hidden</small></div>
                    <span class="status {{ $employee->status }}">{{ ucfirst($employee->status) }}</span>
                    @if($employee->role === 'employee')
                        <div class="employee-actions">
                            <form class="public-email-form" method="POST" action="{{ route('admin.employees.public-email', $employee) }}">@csrf
                                <input type="email" name="public_email" value="{{ $employee->public_email }}" aria-label="U-Mail address for {{ $employee->name }}" required>
                                <button class="soft-button">Update U-Mail address</button>
                            </form>
                            @if($employee->status === 'pending')
                                <form method="POST" action="{{ route('admin.employees.activation', $employee) }}">@csrf<button class="soft-button">Resend activation</button></form>
                            @else
                                <form method="POST" action="{{ route('admin.employees.status', $employee) }}">@csrf
                                    <input type="hidden" name="status" value="{{ $employee->status === 'inactive' ? 'active' : 'inactive' }}">
                                    <button class="soft-button">{{ $employee->status === 'inactive' ? 'Reactivate' : 'Deactivate' }}</button>
                                </form>
                            @endif
                            @if($employee->status === 'active')
                                <form method="POST" action="{{ route('admin.employees.promote', $employee) }}">@csrf<button class="soft-button" data-confirm="Promote this employee to administrator?">Promote to admin</button></form>
                            @endif
                            <form method="POST" action="{{ route('admin.employees.destroy', $employee) }}">@csrf @method('DELETE')
                                <button class="soft-button danger-button" data-confirm="Delete this employee account? Historical mail will be retained.">Delete</button>
                            </form>
                            @if($employee->hasMfa())
                                <form method="POST" action="{{ route('admin.employees.mfa-reset', $employee) }}">@csrf
                                    <input type="hidden" name="reason" value="Employee requested MFA recovery">
                                    <button class="soft-button danger-button" data-confirm="Reset this employee MFA?">Reset MFA</button>
                                </form>
                            @endif
                        </div>
                    @else
                        @if(auth()->user()->isOwner() && !$employee->is(auth()->user()) && $employee->hasMfa())
                            <form method="POST" action="{{ route('admin.employees.mfa-reset', $employee) }}">@csrf
                                <input type="hidden" name="reason" value="Owner-approved administrator MFA recovery">
                                <button class="soft-button danger-button" data-confirm="Reset this administrator MFA?">Reset admin MFA</button>
                            </form>
                        @else
                            <span class="admin-account-note">Admin password resets are self-service</span>
                        @endif
                    @endif
                </article>
            @empty
                <div class="empty-state compact-empty account-empty"><x-icon name="search" /><h2>No matching accounts</h2><p>Try a name, U-Mail address, phone number, or fewer filters.</p><a class="soft-button" href="{{ route('admin.employees') }}">Show all accounts</a></div>
            @endforelse
            <div class="pagination">{{ $employees->links() }}</div>
        </div>
    </div>
</section>
@endsection
