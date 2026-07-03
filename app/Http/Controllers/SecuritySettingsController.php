<?php

namespace App\Http\Controllers;

use App\Services\AccountCredentialService;
use App\Services\MfaService;
use App\Services\SecurityAuditService;
use App\Services\SessionService;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SecuritySettingsController extends Controller
{
    public function index(Request $request, TotpService $totp)
    {
        $user = $request->user()->load('mfaMethods');
        $pendingTotp = $user->mfaMethods->first(fn ($method) => $method->type === 'totp' && ! $method->confirmed_at);

        return view('security.settings', [
            'user' => $user,
            'pendingTotp' => $pendingTotp,
            'qrDataUri' => $pendingTotp ? $totp->qrDataUri($pendingTotp->secret_encrypted, $user->mailAddress()) : null,
            'recoveryCodes' => session('recovery_codes', []),
        ]);
    }

    public function beginTotp(Request $request, MfaService $mfa, SecurityAuditService $audit)
    {
        $mfa->beginTotpEnrollment($request->user());
        $audit->record('mfa.totp_enrollment_started', $request->user(), request: $request);

        return back()->with('status', 'Scan the QR code and confirm a code to finish setup.');
    }

    public function confirmTotp(Request $request, MfaService $mfa, SecurityAuditService $audit)
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $codes = $mfa->confirmTotp($request->user(), $data['code']);
        $audit->record('mfa.totp_enabled', $request->user(), request: $request);

        return back()->with('status', 'Authenticator MFA enabled. Store the recovery codes safely.')->with('recovery_codes', $codes);
    }

    public function enableEmail(Request $request, MfaService $mfa, SecurityAuditService $audit)
    {
        $mfa->enableEmail($request->user());
        $audit->record('mfa.email_enabled', $request->user(), request: $request);

        return back()->with('status', 'Email-code MFA enabled.');
    }

    public function disable(Request $request, MfaService $mfa, SessionService $sessions, SecurityAuditService $audit)
    {
        $data = $request->validate(['type' => ['required', Rule::in(['totp', 'email'])]]);
        $mfa->disable($request->user(), $data['type']);
        $sessions->revoke($request->user(), $request->session()->getId());
        $audit->record('mfa.method_disabled', $request->user(), metadata: ['method' => $data['type']], request: $request);

        return back()->with('status', ucfirst($data['type']).' MFA disabled.');
    }

    public function regenerateRecovery(Request $request, MfaService $mfa, SecurityAuditService $audit)
    {
        abort_unless($mfa->enabledMethods($request->user())->contains('totp'), 422);
        $codes = $mfa->regenerateRecoveryCodes($request->user());
        $audit->record('mfa.recovery_codes_regenerated', $request->user(), request: $request);

        return back()->with('status', 'New recovery codes generated.')->with('recovery_codes', $codes);
    }

    public function changePassword(Request $request, AccountCredentialService $credentials, SessionService $sessions, SecurityAuditService $audit)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->letters()->numbers()->symbols()],
        ]);
        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            $audit->record('password.change_failed', $user, $user, request: $request);
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password different from your current password.']);
        }

        $user->update(['password' => $data['password']]);
        $credentials->store($user, $data['password']);
        $sessions->revoke($user, $request->session()->getId());
        $request->session()->forget('auth.password_confirmed_at');
        $audit->record('password.changed', $user, $user, request: $request);

        return back()->with('status', 'Password changed. Other signed-in devices were disconnected.');
    }
}
