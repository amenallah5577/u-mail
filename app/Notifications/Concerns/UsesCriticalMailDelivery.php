<?php

namespace App\Notifications\Concerns;

trait UsesCriticalMailDelivery
{
    public function viaQueues(): array
    {
        return ['mail' => config('external_mail.critical_queue')];
    }

    public function viaConnections(): array
    {
        return ['mail' => app()->environment('local') ? 'sync' : config('queue.default')];
    }
}
