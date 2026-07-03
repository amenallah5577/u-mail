<?php

namespace App\Mail;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment as MailAttachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class ExternalMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Message $internalMessage) {}

    public function envelope(): Envelope
    {
        $sender = $this->internalMessage->sender;
        $address = $sender?->mailAddress() ?: $this->internalMessage->sender_email ?: config('mail.from.address');
        $name = $sender?->name ?: $this->internalMessage->sender_name ?: config('mail.from.name');

        return new Envelope(
            from: new Address($address, $name),
            replyTo: [new Address($address, $name)],
            subject: $this->internalMessage->subject,
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->internalMessage->internet_message_id,
            references: array_values(array_filter([$this->internalMessage->in_reply_to])),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.external-message');
    }

    public function attachments(): array
    {
        return $this->internalMessage->attachments
            ->map(fn ($attachment) => MailAttachment::fromStorageDisk('local', $attachment->path)
                ->as($attachment->original_name)
                ->withMime($attachment->mime_type))
            ->all();
    }
}
