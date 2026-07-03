<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageRecipient extends Model
{
    protected $fillable = ['message_id', 'user_id', 'email', 'name', 'type'];

    protected $appends = ['display_name'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name ?: $this->email;
    }
}
