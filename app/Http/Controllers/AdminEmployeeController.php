<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\AccountApprovedNotification;
use App\Services\AccountCredentialService;
use App\Services\AccountTokenService;
use App\Services\MailcowAddressSyncService;
use App\Services\MfaService;
use App\Services\PublicEmailService;
use App\Services\SecurityAuditService;
use App\Services\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminEmployeeController extends Controller
{
    public function index(Request $request)
    {
        $search = Str::of((string) $request->string('q'))->trim()->limit(100, '')->toString();
        $role = in_array($request->query('role'), ['employee', 'admin'], true) ? $request->query('role') : null;
        $status = in_array($request->query('status'), ['active', 'pending', 'inactive'], true) ? $request->query('status') : null;
        $directory = User::whereNotIn('status', ['email_verification', 'requested', 'rejected']);
        $employeeTotal = (clone $directory)->count();

        $directory->matchingAccount($search)
            ->when($role, fn ($query) => $query->where('role', $role))
            ->when($status, fn ($query) => $query->where('status', $status));

        return view('admin.employees', [
            'requests' => User::where('status', 'requested')->whereNotNull('email_verified_at')->orderBy('registration_requested_at')->get(),
            'employees' => $directory->orderBy('name')->paginate(30)->withQueryString(),
            'employeeTotal' => $employeeTotal,
            'search' => $search,
            'roleFilter' => $role,
            'statusFilter' => $status,
        ]);
    }

    public function store(Request $request, AccountTokenService $tokens, PublicEmailService $publicEmails, MailcowAddressSyncService $mailcow, SecurityAuditService $audit)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);
        $data['email'] = strtolower($data['email']);
        if (User::withTrashed()->where('public_email', $data['email'])->exists()) {
            throw ValidationException::withMessages(['email' => 'That address is already used as a U-Mail address.']);
        }
        $data['role'] = 'employee';
        $data['status'] = 'pending';
        $data['public_email'] = $publicEmails->generate($data['name']);
        $user = User::create($data);
        $mailcow->sync($user);
        $tokens->issueAndSend($user, 'activation', $request->user());
        $audit->record('admin.employee_created', $request->user(), $user, request: $request);

        return back()->with('status', 'Employee created. The activation code was sent to the private contact email on file.');
    }

    public function status(Request $request, User $user, SessionService $sessions, MailcowAddressSyncService $mailcow, SecurityAuditService $audit)
    {
        abort_if($request->user()->is($user) || $user->isAdmin(), 422, 'Administrator accounts cannot be changed here.');
        $data = $request->validate(['status' => ['required', 'in:active,inactive']]);
        $user->update(['status' => $data['status']]);
        if ($data['status'] === 'inactive') {
            $sessions->revoke($user);
            $mailcow->disable($user);
        } else {
            $mailcow->sync($user);
        }
        $audit->record('admin.employee_status_changed', $request->user(), $user, ['status' => $data['status']], $request);

        return back()->with('status', 'Employee status updated.');
    }

    public function resendActivation(Request $request, User $user, AccountTokenService $tokens, SecurityAuditService $audit)
    {
        abort_unless($user->role === 'employee' && $user->status === 'pending', 422, 'Only pending employee accounts can receive activation codes.');
        $tokens->issueAndSend($user, 'activation', $request->user());
        $audit->record('admin.activation_resent', $request->user(), $user, request: $request);

        return back()->with('status', 'A new activation code was sent to the private contact email on file.');
    }

    public function promote(Request $request, User $user, SessionService $sessions, SecurityAuditService $audit)
    {
        abort_unless($user->role === 'employee' && $user->status === 'active', 422, 'Only active employee accounts can be promoted.');
        $user->update(['role' => 'admin']);
        $sessions->revoke($user);
        $audit->record('admin.employee_promoted', $request->user(), $user, request: $request);

        return back()->with('status', $user->name.' is now an administrator.');
    }

    public function destroy(Request $request, User $user, SessionService $sessions, MailcowAddressSyncService $mailcow, SecurityAuditService $audit)
    {
        abort_if($request->user()->is($user) || $user->isAdmin(), 422, 'Administrator accounts cannot be deleted here.');
        $sessions->revoke($user);
        $mailcow->disable($user);
        $audit->record('admin.employee_deleted', $request->user(), $user, request: $request);
        if ($user->profile_photo_path) {
            Storage::disk('local')->delete($user->profile_photo_path);
        }
        $user->delete();

        return back()->with('status', 'Employee account deleted. Historical mail was retained.');
    }

    public function publicEmail(Request $request, User $user, PublicEmailService $publicEmails, MailcowAddressSyncService $mailcow, SecurityAuditService $audit)
    {
        $data = $request->validate(['public_email' => ['required', 'email', 'max:255']]);
        $old = $user->public_email;
        $user->update(['public_email' => $publicEmails->normalizeRequested($data['public_email'], $user)]);
        $mailcow->sync($user);
        $audit->record('admin.public_email_changed', $request->user(), $user, ['old' => $old, 'new' => $user->public_email], $request);

        return back()->with('status', 'U-Mail address updated.');
    }

    public function approve(Request $request, User $user, AccountCredentialService $credentials, MailcowAddressSyncService $mailcow, SecurityAuditService $audit)
    {
        abort_unless($user->role === 'employee' && $user->status === 'requested', 422, 'Only account requests can be approved.');
        abort_unless($user->email_verified_at && filled($user->email), 422, 'The requester must confirm their contact email before approval.');
        $password = Str::password(16);
        $user->update([
            'password' => $password,
            'status' => 'active',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'activated_at' => now(),
            'rejected_at' => null,
        ]);
        $credentials->store($user, $password);
        $mailcow->sync($user);
        $user->notify(new AccountApprovedNotification($password));
        $audit->record('admin.registration_approved', $request->user(), $user, request: $request);

        return back()->with('status', $user->name.' was approved. Their U-Mail address and temporary password were sent by email.');
    }

    public function reject(Request $request, User $user, MailcowAddressSyncService $mailcow, SecurityAuditService $audit)
    {
        abort_unless($user->role === 'employee' && $user->status === 'requested', 422, 'Only account requests can be rejected.');
        $user->update(['status' => 'rejected', 'rejected_at' => now(), 'approved_at' => null, 'approved_by' => null]);
        $mailcow->disable($user);
        $audit->record('admin.registration_rejected', $request->user(), $user, request: $request);

        return back()->with('status', $user->name.' account request was rejected.');
    }

    public function resetMfa(Request $request, User $user, MfaService $mfa, SessionService $sessions, SecurityAuditService $audit)
    {
        $actor = $request->user();
        abort_if($actor->is($user), 422, 'Use your recovery codes to recover your own MFA.');
        abort_unless($user->role === 'employee' || $actor->isOwner(), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $mfa->reset($user);
        $sessions->revoke($user);
        $audit->record('admin.mfa_reset', $actor, $user, ['reason' => $data['reason']], $request);

        return back()->with('status', 'MFA methods reset for '.$user->name.'.');
    }
}
