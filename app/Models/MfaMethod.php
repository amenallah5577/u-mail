<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MfaMethod extends Model
{
    protected $fillable = ['user_id', 'type', 'secret_encrypted', 'confirmed_at'];

    protected $hidden = ['secret_encrypted'];

    protected function casts(): array
    {
        return [
            'secret_encrypted' => 'encrypted',
            'confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
