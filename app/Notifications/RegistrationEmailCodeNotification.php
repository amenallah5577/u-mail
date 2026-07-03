<?php

namespace App\Notifications;

use App\Notifications\Concerns\UsesCriticalMailDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationEmailCodeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
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
            ->subject('Confirm your contact email for U-Mail')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Use this code to confirm your contact email and submit your U-Mail account request.')
            ->line('Your confirmation code is: '.$this->code)
            ->line('This code expires in 15 minutes.')
            ->action('Confirm contact email', route('register.verify', ['email' => $notifiable->email]))
            ->line('If you did not request a U-Mail account, you can ignore this email.');
    }
}
