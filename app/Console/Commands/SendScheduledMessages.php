<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Services\MailService;
use Illuminate\Console\Command;

class SendScheduledMessages extends Command
{
    protected $signature = 'mail:send-scheduled';

    protected $description = 'Send scheduled U-Mail messages that are due';

    public function handle(MailService $mail): int
    {
        $sent = 0;
        Message::where('status', 'scheduled')
            ->where('scheduled_send_at', '<=', now())
            ->with(['sender', 'recipients'])
            ->chunkById(50, function ($messages) use ($mail, &$sent): void {
                foreach ($messages as $message) {
                    if (! $message->sender?->isActive()) {
                        continue;
                    }

                    $mail->send($message->sender, [
                        'to' => $message->recipients->where('type', 'to')->pluck('email')->join(', '),
                        'cc' => $message->recipients->where('type', 'cc')->pluck('email')->join(', '),
                        'bcc' => $message->recipients->where('type', 'bcc')->pluck('email')->join(', '),
                        'subject' => $message->subject,
                        'body_html' => $message->body_html,
                        'thread_id' => $message->thread_id,
                        'parent_id' => $message->parent_id,
                    ], [], $message);
                    $sent++;
                }
            });

        $this->info("Sent {$sent} scheduled messages.");

        return self::SUCCESS;
    }
}
