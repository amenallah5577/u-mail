<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MailboxEntry extends Model
{
    protected $fillable = ['message_id', 'user_id', 'folder', 'is_read', 'is_starred', 'trashed_at', 'snoozed_until'];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'is_starred' => 'boolean',
            'trashed_at' => 'datetime',
            'snoozed_until' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(MailLabel::class, 'mail_label_mailbox_entry')->withTimestamps();
    }

}
