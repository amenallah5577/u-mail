<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class SessionService
{
    public function revoke(User $user, ?string $exceptSessionId = null): void
    {
        $query = DB::table(config('session.table', 'sessions'))->where('user_id', $user->id);

        if ($exceptSessionId) {
            $query->where('id', '!=', $exceptSessionId);
        }

        $query->delete();
        $user->forceFill(['remember_token' => null])->save();
    }
}
