<?php

namespace App\Notifications;

use App\Notifications\Concerns\UsesCriticalMailDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MfaCodeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, UsesCriticalMailDelivery;

    public function __construct(public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your U-Mail verification code')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your U-Mail sign-in verification code is: '.$this->code)
            ->line('This code expires in 10 minutes and can only be used once.')
            ->line('If you did not attempt to sign in, contact a U-Mail administrator.');
    }
}
