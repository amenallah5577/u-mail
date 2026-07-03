<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'email_verified_at', 'public_email', 'public_email_synced_at', 'phone', 'profile_photo_path', 'password',
        'role', 'status', 'registration_requested_at', 'approved_at', 'approved_by', 'rejected_at',
        'activated_at', 'last_login_at', 'mail_notifications_enabled', 'theme_preference', 'ai_assistance_enabled',
        'onboarding_tour_completed_at', 'onboarding_tour_version',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = [
        'theme_preference' => 'light',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'public_email_synced_at' => 'datetime',
            'registration_requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'password' => 'hashed',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'mail_notifications_enabled' => 'boolean',
            'ai_assistance_enabled' => 'boolean',
            'onboarding_tour_completed_at' => 'datetime',
            'onboarding_tour_version' => 'integer',
        ];
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function mailboxEntries(): HasMany
    {
        return $this->hasMany(MailboxEntry::class);
    }

    public function mailLabels(): HasMany
    {
        return $this->hasMany(MailLabel::class);
    }

    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    public function credential(): HasOne
    {
        return $this->hasOne(AccountCredential::class);
    }

    public function mfaMethods(): HasMany
    {
        return $this->hasMany(MfaMethod::class);
    }

    public function mfaRecoveryCodes(): HasMany
    {
        return $this->hasMany(MfaRecoveryCode::class);
    }

    public function mfaChallenges(): HasMany
    {
        return $this->hasMany(MfaChallenge::class);
    }

    public function hasMfa(): bool
    {
        return $this->mfaMethods()->whereNotNull('confirmed_at')->exists();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOwner(): bool
    {
        return $this->isAdmin()
            && filled(config('owner.email'))
            && strtolower($this->email) === strtolower(config('owner.email'));
    }

    public function mailAddress(): string
    {
        return (string) ($this->public_email ?: $this->email);
    }

    public function scopeMatchingAccount(Builder $query, ?string $search): Builder
    {
        $search = trim((string) $search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($search) {
            $like = '%'.$search.'%';

            $query->where('name', 'like', $like)
                ->orWhere('public_email', 'like', $like)
                ->orWhere('phone', 'like', $like);
        });
    }

    public function routeNotificationForMail(mixed $notification = null): ?string
    {
        return $this->email;
    }
}
