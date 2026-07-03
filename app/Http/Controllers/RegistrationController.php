<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\RegistrationEmailCodeNotification;
use App\Services\AccountTokenService;
use App\Services\AuthenticationSecurityService;
use App\Services\PublicEmailService;
use App\Services\SecurityAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    public function show()
    {
        return view('auth.register');
    }

    public function store(Request $request, PublicEmailService $publicEmails, AccountTokenService $tokens, SecurityAuditService $audit, AuthenticationSecurityService $security)
    {
        $security->ensureTurnstile($request);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+().\s-]+$/'],
        ], [
            'phone.regex' => 'Use a valid phone number containing only numbers and phone symbols.',
        ]);

        $email = strtolower(trim($data['email']));
        $phone = filled($data['phone'] ?? null) ? trim($data['phone']) : null;
        $key = 'registration:'.hash('sha256', $email.':'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['email' => 'Too many account requests. Try again later.']);
        }
        RateLimiter::hit($key, 3600);

        $existing = User::withTrashed()
            ->where(function ($query) use ($email, $phone) {
                $query->when($email, fn ($q) => $q->orWhere('email', $email)->orWhere('public_email', $email))
                    ->when($phone, fn ($q) => $q->orWhere('phone', $phone));
            })
            ->first();
        if ($existing && ! in_array($existing->status, ['rejected', 'email_verification'], true)) {
            return back()->with('status', 'Your request has already been received. An administrator will review it.');
        }
        if ($email && User::withTrashed()->where('email', $email)->when($existing, fn ($query) => $query->where('id', '!=', $existing->id))->exists()) {
            return back()->with('status', 'Your request has already been received. An administrator will review it.');
        }

        $name = trim($data['first_name'].' '.$data['last_name']);
        if ($existing?->trashed()) {
            $existing->restore();
        }
        $user = $existing ?? new User;
        $user->fill([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'public_email' => $existing?->public_email ?: $publicEmails->generate($name, $existing),
            'password' => null,
            'role' => 'employee',
            'status' => 'email_verification',
            'email_verified_at' => null,
            'registration_requested_at' => null,
            'approved_at' => null,
            'approved_by' => null,
            'rejected_at' => null,
        ])->save();
        $user->notify(new RegistrationEmailCodeNotification($tokens->issue($user, 'registration_email')));
        $request->session()->put('registration_verification_user_id', $user->id);
        $audit->record('registration.email_code_sent', target: $user, metadata: ['has_phone' => filled($phone)], request: $request);

        return redirect()->route('register.verify')->with('status', 'We sent a confirmation code to '.$email.'.');
    }

    public function showVerification(Request $request)
    {
        $user = $this->verificationUser($request);

        return view('auth.register-verify', [
            'email' => filled($request->query('email'))
                ? strtolower((string) $request->query('email'))
                : $user?->email,
        ]);
    }

    public function verify(Request $request, AccountTokenService $tokens, AuthenticationSecurityService $security, SecurityAuditService $audit)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'digits:6'],
        ]);
        $email = strtolower(trim($data['email']));
        $security->ensureTokenAllowed($request, 'registration_email', $email);
        $user = User::where('email', $email)->where('status', 'email_verification')->first();

        if (! $user) {
            $security->recordTokenFailure($request, 'registration_email', $email);
            throw ValidationException::withMessages(['code' => 'The confirmation code is invalid or has expired.']);
        }

        try {
            $tokens->consume($user, 'registration_email', $data['code']);
        } catch (ValidationException $exception) {
            $security->recordTokenFailure($request, 'registration_email', $email);
            throw $exception;
        }

        $user->update([
            'email_verified_at' => now(),
            'status' => 'requested',
            'registration_requested_at' => now(),
        ]);
        $security->clearTokenFailures($request, 'registration_email', $email);
        $request->session()->forget('registration_verification_user_id');
        $audit->record('registration.email_verified', target: $user, request: $request);

        return redirect()->route('login')->with('status', 'Your contact email is confirmed. An administrator will review your account request.');
    }

    public function resend(Request $request, AccountTokenService $tokens, SecurityAuditService $audit)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = strtolower(trim($data['email']));
        $key = 'registration-verification-send:'.hash('sha256', $email.':'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['email' => 'Too many confirmation codes requested. Try again later.']);
        }

        $user = User::where('email', $email)->where('status', 'email_verification')->first();
        if ($user) {
            RateLimiter::hit($key, 900);
            $user->notify(new RegistrationEmailCodeNotification($tokens->issue($user, 'registration_email')));
            $request->session()->put('registration_verification_user_id', $user->id);
            $audit->record('registration.email_code_resent', target: $user, request: $request);
        }

        return back()->with('status', 'If this email has a pending request, a new confirmation code was sent.');
    }

    private function verificationUser(Request $request): ?User
    {
        return User::whereKey($request->session()->get('registration_verification_user_id'))
            ->where('status', 'email_verification')
            ->first();
    }
}
