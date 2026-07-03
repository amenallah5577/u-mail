<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountCredentialService;
use App\Services\AccountTokenService;
use App\Services\AuthenticationSecurityService;
use App\Services\SecurityAuditService;
use App\Services\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ActivationController extends Controller
{
    public function showActivation()
    {
        return view('auth.activate');
    }

    public function showReset()
    {
        return view('auth.reset-password');
    }

    public function activate(Request $request, AccountTokenService $tokens, AccountCredentialService $credentials, SecurityAuditService $audit, AuthenticationSecurityService $security)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->letters()->numbers()->symbols()],
        ]);
        $email = strtolower($data['email']);
        $security->ensureTokenAllowed($request, 'activation', $email);
        $user = User::where(fn ($query) => $query->where('email', $email)->orWhere('public_email', $email))->first();
        if (! $user || $user->status !== 'pending') {
            $security->recordTokenFailure($request, 'activation', $email);
            $audit->record('activation.failed', target: $user, request: $request);
            throw ValidationException::withMessages(['email' => 'The account cannot be activated with those details.']);
        }

        try {
            $tokens->consume($user, 'activation', $data['code']);
        } catch (ValidationException $exception) {
            $security->recordTokenFailure($request, 'activation', $email);
            $audit->record('activation.failed', target: $user, request: $request);
            throw $exception;
        }

        $security->clearTokenFailures($request, 'activation', $email);
        $user->update([
            'password' => $data['password'],
            'status' => 'active',
            'activated_at' => $user->activated_at ?? now(),
        ]);
        $credentials->store($user, $data['password']);
        $audit->record('account.activated', target: $user, request: $request);

        return redirect()->route($user->isAdmin() ? 'admin.login' : 'login')->with('status', 'Password set. You can now sign in.');
    }

    public function requestReset(Request $request, AccountTokenService $tokens, AuthenticationSecurityService $security)
    {
        $security->ensureTurnstile($request);
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower($data['email']);
        $accountKey = 'reset-request:'.$email;
        $ipKey = 'reset-request-ip:'.$request->ip();
        if (! RateLimiter::tooManyAttempts($accountKey, 3) && ! RateLimiter::tooManyAttempts($ipKey, 10)) {
            RateLimiter::hit($accountKey, 3600);
            RateLimiter::hit($ipKey, 3600);
            $user = User::where('public_email', $email)->where('status', 'active')->first();

            if ($user) {
                $tokens->issueAndSend($user, 'reset');
            }
        }

        return back()->with('status', 'If an active account exists for that U-Mail address, a reset code has been sent.');
    }

    public function reset(Request $request, AccountTokenService $tokens, AccountCredentialService $credentials, SessionService $sessions, SecurityAuditService $audit, AuthenticationSecurityService $security)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->letters()->numbers()->symbols()],
        ]);
        $email = strtolower($data['email']);
        $security->ensureTokenAllowed($request, 'reset', $email);
        $user = User::where('public_email', $email)->where('status', 'active')->first();
        if (! $user) {
            $security->recordTokenFailure($request, 'reset', $email);
            $audit->record('password.reset_failed', metadata: ['email' => $email], request: $request);
            throw ValidationException::withMessages(['code' => 'The one-time code is invalid or has expired.']);
        }

        try {
            $tokens->consume($user, 'reset', $data['code']);
        } catch (ValidationException $exception) {
            $security->recordTokenFailure($request, 'reset', $email);
            $audit->record('password.reset_failed', target: $user, request: $request);
            throw $exception;
        }

        $security->clearTokenFailures($request, 'reset', $email);
        $user->update(['password' => $data['password']]);
        $credentials->store($user, $data['password']);
        $sessions->revoke($user);
        $audit->record('password.reset', target: $user, request: $request);

        return redirect()->route($user->isAdmin() ? 'admin.login' : 'login')->with('status', 'Password reset. You can now sign in.');
    }
}
