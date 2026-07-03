<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicEmailService
{
    public function generate(string $name, ?User $except = null): string
    {
        $base = Str::of(Str::ascii($name))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '.')
            ->trim('.')
            ->value() ?: 'employee';
        $domain = config('external_mail.public_domain');
        $candidate = $base.'@'.$domain;
        $suffix = 2;

        while ($this->exists($candidate, $except)) {
            $candidate = $base.$suffix.'@'.$domain;
            $suffix++;
        }

        return $candidate;
    }

    public function normalizeRequested(string $email, ?User $except = null): string
    {
        $email = strtolower(trim($email));
        $domain = config('external_mail.public_domain');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! str_ends_with($email, '@'.$domain)) {
            throw ValidationException::withMessages([
                'public_email' => 'Use an address ending in @'.$domain.'.',
            ]);
        }
        if ($this->exists($email, $except)) {
            throw ValidationException::withMessages(['public_email' => 'That U-Mail address is already in use.']);
        }

        return $email;
    }

    private function exists(string $email, ?User $except): bool
    {
        return User::withTrashed()
            ->where(fn ($query) => $query->where('public_email', $email)->orWhere('email', $email))
            ->when($except, fn ($query) => $query->where('id', '!=', $except->id))
            ->exists();
    }
}
