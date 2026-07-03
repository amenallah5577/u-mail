<?php

namespace App\Services;

use App\Models\MfaChallenge;
use App\Models\MfaMethod;
use App\Models\MfaRecoveryCode;
use App\Models\User;
use App\Notifications\MfaCodeNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class MfaService
{
    public function __construct(private TotpService $totp) {}

    public function enabledMethods(User $user): Collection
    {
        return $user->mfaMethods()->whereNotNull('confirmed_at')->pluck('type');
    }

    public function beginTotpEnrollment(User $user): MfaMethod
    {
        return MfaMethod::updateOrCreate(
            ['user_id' => $user->id, 'type' => 'totp'],
            ['secret_encrypted' => $this->totp->generateSecret(), 'confirmed_at' => null],
        );
    }

    public function confirmTotp(User $user, string $code): array
    {
        $method = $user->mfaMethods()->where('type', 'totp')->firstOrFail();
        if (! $this->totp->verify($method->secret_encrypted, $code)) {
            throw ValidationException::withMessages(['code' => 'The authenticator code is invalid.']);
        }

        $method->update(['confirmed_at' => now()]);

        return $this->regenerateRecoveryCodes($user);
    }

    public function enableEmail(User $user): MfaMethod
    {
        return MfaMethod::updateOrCreate(
            ['user_id' => $user->id, 'type' => 'email'],
            ['secret_encrypted' => null, 'confirmed_at' => now()],
        );
    }

    public function disable(User $user, string $type): void
    {
        $user->mfaMethods()->where('type', $type)->delete();
        if ($type === 'totp') {
            $user->mfaRecoveryCodes()->delete();
        }
    }

    public function reset(User $user): void
    {
        $user->mfaMethods()->delete();
        $user->mfaRecoveryCodes()->delete();
        $user->mfaChallenges()->delete();
    }

    public function issueEmailChallenge(User $user): void
    {
        $key = 'mfa-email-send:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['method' => 'Too many email codes requested. Try again later.']);
        }
        RateLimiter::hit($key, 900);

        $user->mfaChallenges()->where('type', 'email')->whereNull('used_at')->update(['used_at' => now()]);
        $code = (string) random_int(100000, 999999);
        MfaChallenge::create([
            'user_id' => $user->id,
            'type' => 'email',
            'token_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
        ]);
        $user->notify(new MfaCodeNotification($code));
    }

    public function consumeEmailChallenge(User $user, string $code): void
    {
        $challenge = $user->mfaChallenges()->where('type', 'email')->whereNull('used_at')->latest('id')->first();
        if (! $challenge || $challenge->expires_at->isPast() || $challenge->failed_attempts >= 5) {
            throw ValidationException::withMessages(['code' => 'The email code is invalid or expired.']);
        }

        if (! $this->codeMatches($challenge->token_hash, $code)) {
            $challenge->increment('failed_attempts');
            throw ValidationException::withMessages(['code' => 'The email code is invalid or expired.']);
        }

        $challenge->update(['used_at' => now()]);
    }

    public function consumeRecoveryCode(User $user, string $code): void
    {
        $recovery = $user->mfaRecoveryCodes()->whereNull('used_at')->get()
            ->first(fn (MfaRecoveryCode $recovery) => Hash::check(strtoupper(trim($code)), $recovery->code_hash));

        if (! $recovery) {
            throw ValidationException::withMessages(['code' => 'The recovery code is invalid or already used.']);
        }

        $recovery->update(['used_at' => now()]);
    }

    public function regenerateRecoveryCodes(User $user): array
    {
        $user->mfaRecoveryCodes()->delete();
        $codes = collect(range(1, 8))->map(fn () => strtoupper(bin2hex(random_bytes(4))));
        foreach ($codes as $code) {
            MfaRecoveryCode::create(['user_id' => $user->id, 'code_hash' => Hash::make($code)]);
        }

        return $codes->all();
    }

    private function codeMatches(string $storedHash, string $code): bool
    {
        if (strlen($storedHash) === 64 && ctype_xdigit($storedHash)) {
            return hash_equals($storedHash, hash('sha256', $code));
        }

        return Hash::check($code, $storedHash);
    }
}
