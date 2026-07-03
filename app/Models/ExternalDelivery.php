<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalDelivery extends Model
{
    protected $fillable = [
        'message_id', 'status', 'attempts', 'last_error', 'queued_at', 'delivered_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function userLabel(): string
    {
        return match ($this->status) {
            'delivered' => 'Delivered outside U-Mail',
            'failed' => 'Could not deliver',
            default => 'Sending outside U-Mail',
        };
    }
}
