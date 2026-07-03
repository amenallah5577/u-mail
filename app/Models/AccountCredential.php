<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountCredential extends Model
{
    protected $fillable = ['user_id', 'password_encrypted'];

    protected $hidden = ['password_encrypted'];

    protected function casts(): array
    {
        return [
            'password_encrypted' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
