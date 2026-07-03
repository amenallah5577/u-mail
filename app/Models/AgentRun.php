<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    protected $fillable = [
        'user_id',
        'prompt',
        'context_type',
        'context_id',
        'status',
        'result',
        'error_text',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentMessage::class);
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }
}
