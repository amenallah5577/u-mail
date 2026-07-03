<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSetting extends Model
{
    protected $fillable = ['enabled', 'provider', 'local_endpoint', 'local_model', 'updated_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public static function current(): self
    {
        return static::firstOrCreate([], [
            'enabled' => (bool) config('ai.enabled', false),
            'provider' => (string) config('ai.provider', 'none'),
            'local_endpoint' => config('ai.local_endpoint'),
            'local_model' => config('ai.local_model'),
        ]);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
