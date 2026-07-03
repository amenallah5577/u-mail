<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageTemplate extends Model
{
    protected $fillable = ['user_id', 'name', 'subject', 'body_html'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
