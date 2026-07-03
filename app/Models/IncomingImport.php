<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomingImport extends Model
{
    protected $fillable = [
        'internet_message_id', 'sender_email', 'sender_name', 'recipient_addresses', 'subject',
        'status', 'routed_user_ids', 'message_id', 'reason', 'raw_path',
    ];

    protected function casts(): array
    {
        return [
            'recipient_addresses' => 'array',
            'routed_user_ids' => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
