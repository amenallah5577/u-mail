<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarEvent extends Model
{
    public const SCOPE_PERSONAL = 'personal';
    public const SCOPE_SHARED = 'shared';

    protected $fillable = [
        'scope',
        'owner_id',
        'created_by',
        'title',
        'starts_at',
        'ends_at',
        'location',
        'notes',
        'invitation_token_hash',
        'invitation_created_at',
        'invitation_revoked_at',
        'source_event_id',
        'source_sync_stopped_at',
    ];

    protected $hidden = ['invitation_token_hash'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'invitation_created_at' => 'datetime',
            'invitation_revoked_at' => 'datetime',
            'source_sync_stopped_at' => 'datetime',
        ];
    }

    public static function invitationTokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user) {
            $query->where('scope', self::SCOPE_SHARED)
                ->orWhere(function (Builder $query) use ($user) {
                    $query->where('scope', self::SCOPE_PERSONAL)
                        ->where('owner_id', $user->id);
                });
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function sourceEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_event_id');
    }

    public function acceptedCopies(): HasMany
    {
        return $this->hasMany(self::class, 'source_event_id');
    }

    public function isShared(): bool
    {
        return $this->scope === self::SCOPE_SHARED;
    }

    public function isPersonal(): bool
    {
        return $this->scope === self::SCOPE_PERSONAL;
    }

    public function isAcceptedCopy(): bool
    {
        return filled($this->source_event_id);
    }

    public function isActiveSyncedCopy(): bool
    {
        return $this->isAcceptedCopy() && blank($this->source_sync_stopped_at);
    }

    public function canHaveInvitation(): bool
    {
        return $this->isPersonal() && filled($this->owner_id) && ! $this->isAcceptedCopy();
    }

    public function hasActiveInvitation(): bool
    {
        return $this->canHaveInvitation()
            && filled($this->invitation_token_hash)
            && blank($this->invitation_revoked_at);
    }

    public function stopActiveAcceptedCopySyncs(): void
    {
        $this->acceptedCopies()
            ->whereNull('source_sync_stopped_at')
            ->update(['source_sync_stopped_at' => now()]);
    }

    public function syncActiveAcceptedCopies(array $attributes): void
    {
        $this->acceptedCopies()
            ->whereNull('source_sync_stopped_at')
            ->update([
                'title' => $attributes['title'],
                'starts_at' => $attributes['starts_at'],
                'ends_at' => $attributes['ends_at'],
                'location' => $attributes['location'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);
    }
}
