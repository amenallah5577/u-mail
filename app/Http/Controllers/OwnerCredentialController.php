<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SecurityAuditService;
use Illuminate\Http\Request;

class OwnerCredentialController extends Controller
{
    public function index(Request $request)
    {
        $search = str((string) $request->string('q'))->trim()->limit(100, '')->toString();
        $role = in_array($request->query('role'), ['employee', 'admin'], true) ? $request->query('role') : null;
        $status = in_array($request->query('status'), ['active', 'pending', 'inactive', 'requested', 'email_verification', 'rejected', 'deleted'], true)
            ? $request->query('status')
            : null;
        $accountTotal = User::withTrashed()->count();
        $accounts = User::withTrashed()
            ->matchingAccount($search)
            ->when($role, fn ($query) => $query->where('role', $role))
            ->when($status === 'deleted', fn ($query) => $query->onlyTrashed())
            ->when($status && $status !== 'deleted', fn ($query) => $query->whereNull('deleted_at')->where('status', $status))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();
        $revealedAccount = null;
        if ($revealedId = session('revealed_credential_user_id')) {
            $revealedAccount = User::withTrashed()->with('credential')->find($revealedId);
        }

        return response()
            ->view('owner.credentials', compact('accounts', 'revealedAccount', 'accountTotal', 'search', 'role', 'status'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }

    public function reveal(Request $request, int $user, SecurityAuditService $audit)
    {
        $account = User::withTrashed()->findOrFail($user);
        $audit->record('owner.credential_revealed', $request->user(), $account, request: $request);

        return redirect()
            ->route('owner.credentials', $request->only(['q', 'role', 'status', 'page']))
            ->with('revealed_credential_user_id', $account->id);
    }
}
