<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    protected $fillable = [
        'thread_id', 'sender_id', 'sender_email', 'sender_name', 'source', 'internet_message_id',
        'in_reply_to', 'parent_id', 'subject', 'body_html', 'body_text', 'status', 'sent_at', 'scheduled_send_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'scheduled_send_at' => 'datetime'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MailThread::class, 'thread_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id')->withTrashed();
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MessageRecipient::class);
    }

    public function mailboxEntries(): HasMany
    {
        return $this->hasMany(MailboxEntry::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function externalDelivery(): HasOne
    {
        return $this->hasOne(ExternalDelivery::class);
    }

    public function senderDisplayName(): string
    {
        return $this->sender_name ?: $this->sender?->name ?: $this->sender_email ?: 'Outside sender';
    }

    public function senderDisplayEmail(): string
    {
        return $this->sender_email ?: $this->sender?->mailAddress() ?: '';
    }

    public function scheduledLabel(): ?string
    {
        return $this->scheduled_send_at?->format('M j, Y H:i');
    }
}
