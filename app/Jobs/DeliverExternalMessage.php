<?php

namespace App\Jobs;

use App\Mail\ExternalMessageMail;
use App\Models\ExternalDelivery;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverExternalMessage implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    public function __construct(public int $deliveryId) {}

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(): void
    {
        $delivery = ExternalDelivery::with(['message.sender', 'message.recipients', 'message.attachments'])->findOrFail($this->deliveryId);
        if ($delivery->status === 'delivered') {
            return;
        }

        $delivery->update([
            'status' => 'processing',
            'attempts' => $delivery->attempts + 1,
            'last_error' => null,
            'failed_at' => null,
        ]);
        $message = $delivery->message;
        $external = $message->recipients->whereNull('user_id');

        Mail::to($external->where('type', 'to')->pluck('email')->all())
            ->cc($external->where('type', 'cc')->pluck('email')->all())
            ->bcc($external->where('type', 'bcc')->pluck('email')->all())
            ->send(new ExternalMessageMail($message));

        $delivery->update(['status' => 'delivered', 'delivered_at' => now(), 'failed_at' => null]);
    }

    public function failed(?Throwable $exception): void
    {
        ExternalDelivery::whereKey($this->deliveryId)->update([
            'status' => 'failed',
            'last_error' => mb_substr((string) $exception?->getMessage(), 0, 2000),
            'failed_at' => now(),
        ]);
    }
}
