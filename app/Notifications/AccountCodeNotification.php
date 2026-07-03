<?php

namespace App\Notifications;

use App\Notifications\Concerns\UsesCriticalMailDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountCodeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, UsesCriticalMailDelivery;

    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $activation = $this->purpose === 'activation';

        return (new MailMessage)
            ->subject($activation ? 'Activate your U-Mail account' : 'Reset your U-Mail password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line($activation
                ? 'An employee account has been created for you on U-Mail.'
                : 'A password reset was requested for your U-Mail account.')
            ->line('Your single-use code is: '.$this->code)
            ->line($activation ? 'This code expires in 24 hours.' : 'This code expires in 15 minutes.')
            ->action($activation ? 'Activate account' : 'Reset password', route($activation ? 'activate' : 'password.reset'))
            ->line('If you did not expect this message, contact a UTICA administrator.');
    }
}
