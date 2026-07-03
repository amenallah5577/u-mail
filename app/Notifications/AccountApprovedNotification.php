<?php

namespace App\Notifications;

use App\Notifications\Concerns\UsesCriticalMailDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountApprovedNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, UsesCriticalMailDelivery;

    public function __construct(public readonly string $temporaryPassword) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your U-Mail account is ready')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your U-Mail account request was approved.')
            ->line('Your U-Mail address is: '.$notifiable->public_email)
            ->line('Your temporary password is: '.$this->temporaryPassword)
            ->action('Open U-Mail', route('mailbox'))
            ->line('Sign in with your U-Mail address and change the temporary password from Security.');
    }
}
