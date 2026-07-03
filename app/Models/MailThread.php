<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailThread extends Model
{
    protected $fillable = ['created_by', 'subject', 'latest_message_at'];

    protected function casts(): array
    {
        return ['latest_message_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id');
    }
}
