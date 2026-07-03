<?php

namespace App\Services;

use App\Models\AccountToken;
use App\Models\User;
use App\Notifications\AccountCodeNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountTokenService
{
    public function issue(User $user, string $purpose, ?User $creator = null): string
    {
        AccountToken::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $code = (string) random_int(100000, 999999);

        AccountToken::create([
            'user_id' => $user->id,
            'created_by' => $creator?->id,
            'purpose' => $purpose,
            'token_hash' => Hash::make($code),
            'expires_at' => in_array($purpose, ['reset', 'registration_email'], true)
                ? now()->addMinutes(15)
                : now()->addHours(24),
        ]);

        return $code;
    }

    public function issueAndSend(User $user, string $purpose, ?User $creator = null): void
    {
        $user->notify(new AccountCodeNotification($this->issue($user, $purpose, $creator), $purpose));
    }

    public function consume(User $user, string $purpose, string $code): void
    {
        $token = AccountToken::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if (! $token || $token->expires_at->isPast() || $token->failed_attempts >= 5) {
            throw ValidationException::withMessages(['code' => 'The one-time code is invalid or has expired.']);
        }

        if (! $this->codeMatches($token->token_hash, $code)) {
            $token->increment('failed_attempts');
            throw ValidationException::withMessages(['code' => 'The one-time code is invalid or has expired.']);
        }

        $token->update(['used_at' => now()]);
    }

    private function codeMatches(string $storedHash, string $code): bool
    {
        if (strlen($storedHash) === 64 && ctype_xdigit($storedHash)) {
            return hash_equals($storedHash, hash('sha256', $code));
        }

        return Hash::check($code, $storedHash);
    }
}
