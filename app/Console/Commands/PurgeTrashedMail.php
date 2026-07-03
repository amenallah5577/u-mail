<?php

namespace App\Console\Commands;

use App\Models\MailboxEntry;
use App\Services\MailService;
use Illuminate\Console\Command;

class PurgeTrashedMail extends Command
{
    protected $signature = 'mail:purge-trash';

    protected $description = 'Permanently remove mailbox copies left in Trash for more than 30 days';

    public function handle(MailService $mail): int
    {
        $count = 0;
        MailboxEntry::where('folder', 'trash')
            ->where('trashed_at', '<=', now()->subDays(30))
            ->with(['message.attachments'])
            ->chunkById(100, function ($entries) use ($mail, &$count) {
                foreach ($entries as $entry) {
                    $mail->permanentlyDeleteEntry($entry);
                    $count++;
                }
            });
        $this->info("Purged {$count} mailbox copies.");

        return self::SUCCESS;
    }
}
