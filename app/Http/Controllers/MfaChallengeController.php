<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuthenticationSecurityService;
use App\Services\MfaService;
use App\Services\SecurityAuditService;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MfaChallengeController extends Controller
{
    public function show(Request $request, MfaService $mfa)
    {
        $user = $this->pendingUser($request);
        $methods = $mfa->enabledMethods($user);
        abort_if($methods->isEmpty(), 403);

        return view('auth.mfa-challenge', [
            'methods' => $methods,
            'selectedMethod' => $request->session()->get('mfa.selected_method'),
            'recoveryAvailable' => $methods->contains('totp'),
        ]);
    }

    public function select(Request $request, MfaService $mfa, AuthenticationSecurityService $security, SecurityAuditService $audit)
    {
        $security->ensureTurnstile($request);
        $user = $this->pendingUser($request);
        $methods = $mfa->enabledMethods($user);
        $data = $request->validate(['method' => ['required', Rule::in($methods->all())]]);
        $request->session()->put('mfa.selected_method', $data['method']);

        if ($data['method'] === 'email') {
            $mfa->issueEmailChallenge($user);
        }
        $audit->record('mfa.method_selected', target: $user, metadata: ['method' => $data['method']], request: $request);

        return back()->with('status', $data['method'] === 'email' ? 'A verification code was sent to your email.' : 'Enter the code from your authenticator app.');
    }

    public function verify(Request $request, MfaService $mfa, TotpService $totp, SecurityAuditService $audit, AuthenticationSecurityService $security)
    {
        $security->ensureTurnstile($request);
        $user = $this->pendingUser($request);
        $method = $request->session()->get('mfa.selected_method');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'use_recovery' => ['nullable', 'boolean'],
        ]);

        $key = 'mfa-verify:'.$user->id;
        $ipKey = 'mfa-verify-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            $request->session()->put('turnstile_required', true);
            throw ValidationException::withMessages(['code' => 'Too many verification attempts. Try again later.']);
        }

        try {
            if ($request->boolean('use_recovery')) {
                abort_unless($mfa->enabledMethods($user)->contains('totp'), 403);
                $mfa->consumeRecoveryCode($user, $data['code']);
                $method = 'recovery';
            } elseif ($method === 'email') {
                abort_unless($mfa->enabledMethods($user)->contains('email'), 403);
                $mfa->consumeEmailChallenge($user, $data['code']);
            } elseif ($method === 'totp') {
                $totpMethod = $user->mfaMethods()->where('type', 'totp')->whereNotNull('confirmed_at')->firstOrFail();
                if (! $totp->verify($totpMethod->secret_encrypted, $data['code'])) {
                    throw ValidationException::withMessages(['code' => 'The authenticator code is invalid.']);
                }
            } else {
                throw ValidationException::withMessages(['method' => 'Choose a verification method first.']);
            }
        } catch (ValidationException $exception) {
            RateLimiter::hit($key, 900);
            RateLimiter::hit($ipKey, 3600);
            if (RateLimiter::attempts($key) >= config('security.turnstile.failure_threshold')) {
                $request->session()->put('turnstile_required', true);
            }
            $audit->record('mfa.challenge_failed', target: $user, metadata: ['method' => $method], request: $request);
            throw $exception;
        }

        RateLimiter::clear($key);
        $remember = $request->session()->pull('mfa.remember', false);
        $destination = $request->session()->pull('mfa.destination', route('mailbox'));
        $request->session()->forget(['mfa.pending_user_id', 'mfa.selected_method']);
        Auth::login($user, $user->isAdmin() ? false : $remember);
        $request->session()->regenerate();
        if ($user->isAdmin()) {
            $request->session()->put('admin_last_activity', time());
        }
        $user->update(['last_login_at' => now()]);
        $audit->record('mfa.challenge_succeeded', $user, $user, ['method' => $method], $request);
        Cookie::queue(Cookie::forget('u_mail_signed_out'));

        return redirect($destination);
    }

    public function resend(Request $request, MfaService $mfa, AuthenticationSecurityService $security, SecurityAuditService $audit)
    {
        $security->ensureTurnstile($request);
        $user = $this->pendingUser($request);
        abort_unless($mfa->enabledMethods($user)->contains('email'), 403);
        $request->session()->put('mfa.selected_method', 'email');
        $mfa->issueEmailChallenge($user);
        $audit->record('mfa.email_code_resent', target: $user, request: $request);

        return back()->with('status', 'A new verification code was sent.');
    }

    private function pendingUser(Request $request): User
    {
        $user = User::find($request->session()->get('mfa.pending_user_id'));
        abort_unless($user?->isActive(), 403);

        return $user;
    }
}
