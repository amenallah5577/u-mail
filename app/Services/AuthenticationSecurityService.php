<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthenticationSecurityService
{
    public function ensureLoginAllowed(Request $request, string $role, string $email): void
    {
        $emailKey = $this->loginEmailKey($role, $email, $request->ip());
        $ipKey = $this->loginIpKey($role, $request->ip());
        $emailLimit = $role === 'admin' ? 3 : 5;
        $ipLimit = $role === 'admin' ? 15 : 30;

        if (RateLimiter::tooManyAttempts($emailKey, $emailLimit) || RateLimiter::tooManyAttempts($ipKey, $ipLimit)) {
            $request->session()->put('turnstile_required', true);
            throw ValidationException::withMessages(['email' => 'Too many sign-in attempts. Try again later.']);
        }

        $this->ensureTurnstile($request);
    }

    public function recordLoginFailure(Request $request, string $role, string $email): void
    {
        RateLimiter::hit($this->loginEmailKey($role, $email, $request->ip()), 900);
        RateLimiter::hit($this->loginIpKey($role, $request->ip()), 3600);
        if (RateLimiter::attempts($this->loginEmailKey($role, $email, $request->ip())) >= config('security.turnstile.failure_threshold')) {
            $request->session()->put('turnstile_required', true);
        }
    }

    public function clearLoginFailures(Request $request, string $role, string $email): void
    {
        RateLimiter::clear($this->loginEmailKey($role, $email, $request->ip()));
    }

    public function ensureTokenAllowed(Request $request, string $purpose, string $email): void
    {
        if (RateLimiter::tooManyAttempts($this->tokenAccountKey($purpose, $email, $request->ip()), 5)
            || RateLimiter::tooManyAttempts($this->tokenIpKey($purpose, $request->ip()), 30)) {
            $request->session()->put('turnstile_required', true);
            throw ValidationException::withMessages(['code' => 'Too many code attempts. Try again later.']);
        }

        $this->ensureTurnstile($request);
    }

    public function recordTokenFailure(Request $request, string $purpose, string $email): void
    {
        $accountKey = $this->tokenAccountKey($purpose, $email, $request->ip());
        RateLimiter::hit($accountKey, 900);
        RateLimiter::hit($this->tokenIpKey($purpose, $request->ip()), 3600);

        if (RateLimiter::attempts($accountKey) >= config('security.turnstile.failure_threshold')) {
            $request->session()->put('turnstile_required', true);
        }
    }

    public function clearTokenFailures(Request $request, string $purpose, string $email): void
    {
        RateLimiter::clear($this->tokenAccountKey($purpose, $email, $request->ip()));
    }

    public function ensureTurnstile(Request $request): void
    {
        if (! config('security.turnstile.enabled') || app()->environment(['local', 'testing'])) {
            return;
        }

        if (! $request->session()->get('turnstile_required')) {
            return;
        }

        $token = (string) $request->input('cf-turnstile-response');
        $valid = filled($token) && Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => config('security.turnstile.secret_key'),
            'response' => $token,
            'remoteip' => $request->ip(),
        ])->json('success') === true;

        if (! $valid) {
            $request->session()->put('turnstile_required', true);
            throw ValidationException::withMessages(['turnstile' => 'Complete the security check and try again.']);
        }

        $request->session()->forget('turnstile_required');
    }

    private function loginEmailKey(string $role, string $email, ?string $ip): string
    {
        return 'login:'.$role.':'.strtolower($email).':'.$ip;
    }

    private function loginIpKey(string $role, ?string $ip): string
    {
        return 'login-ip:'.$role.':'.$ip;
    }

    private function tokenAccountKey(string $purpose, string $email, ?string $ip): string
    {
        return 'account-token:'.$purpose.':'.strtolower($email).':'.$ip;
    }

    private function tokenIpKey(string $purpose, ?string $ip): string
    {
        return 'account-token-ip:'.$purpose.':'.$ip;
    }
}
