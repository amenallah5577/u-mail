<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;

class SecurityAuditService
{
    public function record(string $event, ?User $actor = null, ?User $target = null, array $metadata = [], ?Request $request = null): SecurityEvent
    {
        $request ??= request();

        return SecurityEvent::create([
            'actor_id' => $actor?->id,
            'target_user_id' => $target?->id,
            'event' => $event,
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 1000),
            'metadata' => $metadata ?: null,
        ]);
    }
}
