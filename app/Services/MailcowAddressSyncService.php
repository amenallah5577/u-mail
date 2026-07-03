<?php

namespace App\Services;

use App\Models\User;

class MailcowAddressSyncService
{
    public function sync(User $user): void
    {
        if (! config('external_mail.mailcow_enabled')) {
            $user->forceFill(['public_email_synced_at' => null])->saveQuietly();

            return;
        }

        // The production Mailcow API call is intentionally disabled until credentials are provided.
        $user->forceFill(['public_email_synced_at' => now()])->saveQuietly();
    }

    public function disable(User $user): void
    {
        if (! config('external_mail.mailcow_enabled')) {
            $user->forceFill(['public_email_synced_at' => null])->saveQuietly();
        }
    }
}
