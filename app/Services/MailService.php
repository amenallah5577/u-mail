<?php

namespace App\Services;

use App\Jobs\DeliverExternalMessage;
use App\Models\Attachment;
use App\Models\ExternalDelivery;
use App\Models\MailboxEntry;
use App\Models\MailThread;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonInterface;

class MailService
{
    public function __construct(private HtmlSanitizer $sanitizer) {}

    public function saveDraft(User $sender, array $data, array $files = [], ?Message $draft = null): Message
    {
        return DB::transaction(function () use ($sender, $data, $files, $draft) {
            if ($draft) {
                abort_unless($draft->sender_id === $sender->id && $draft->status === 'draft', 403);
            }

            $message = $draft ?? new Message(['sender_id' => $sender->id, 'status' => 'draft']);
            $message->fill($this->messageData($data));
            $message->sender_id = $sender->id;
            $message->status = 'draft';
            $message->save();

            MailboxEntry::updateOrCreate(
                ['message_id' => $message->id, 'user_id' => $sender->id],
                ['folder' => 'drafts', 'is_read' => true, 'trashed_at' => null],
            );

            $this->syncRecipients($message, $sender, $data, false);
            $this->storeAttachments($message, $files);

            return $message;
        });
    }

    public function send(User $sender, array $data, array $files = [], ?Message $draft = null): Message
    {
        $message = DB::transaction(function () use ($sender, $data, $files, $draft) {
            if ($draft) {
                abort_unless($draft->sender_id === $sender->id && in_array($draft->status, ['draft', 'scheduled'], true), 403);
            }

            $recipients = $this->resolveRecipients($sender, $data, true);
            $thread = $this->resolveThread($sender, $data, $draft);
            $message = $draft ?? new Message;
            $message->fill($this->messageData($data));
            $message->thread_id = $thread->id;
            $message->sender_id = $sender->id;
            $message->sender_email = $sender->mailAddress();
            $message->sender_name = $sender->name;
            $message->source = 'internal';
            $message->internet_message_id ??= 'u-mail-'.Str::uuid().'@'.config('external_mail.message_id_domain');
            $message->parent_id = $data['parent_id'] ?? null;
            $message->status = 'sent';
            $message->sent_at = now();
            $message->scheduled_send_at = null;
            $message->save();

            $message->recipients()->delete();
            $this->storeRecipients($message, $recipients);

            MailboxEntry::updateOrCreate(
                ['message_id' => $message->id, 'user_id' => $sender->id],
                ['folder' => 'sent', 'is_read' => true, 'trashed_at' => null],
            );

            foreach ($recipients->values()->collapse()->whereNotNull('user_id')->unique('user_id') as $recipient) {
                if ($recipient['user_id'] === $sender->id) {
                    continue;
                }
                MailboxEntry::updateOrCreate(
                    ['message_id' => $message->id, 'user_id' => $recipient['user_id']],
                    ['folder' => 'inbox', 'is_read' => false, 'trashed_at' => null],
                );
            }

            $this->storeAttachments($message, $files);
            $thread->update(['latest_message_at' => $message->sent_at, 'subject' => $message->subject]);
            if ($recipients->values()->collapse()->whereNull('user_id')->isNotEmpty()) {
                ExternalDelivery::updateOrCreate(
                    ['message_id' => $message->id],
                    ['status' => 'queued', 'attempts' => 0, 'last_error' => null, 'queued_at' => now(), 'delivered_at' => null, 'failed_at' => null],
                );
            }

            return $message;
        });

        if ($delivery = $message->externalDelivery) {
            DeliverExternalMessage::dispatch($delivery->id)
                ->onQueue(config('external_mail.external_queue'))
                ->afterCommit();
        }

        return $message;
    }

    public function schedule(User $sender, array $data, CarbonInterface $sendAt, array $files = [], ?Message $draft = null): Message
    {
        return DB::transaction(function () use ($sender, $data, $sendAt, $files, $draft) {
            if ($draft) {
                abort_unless($draft->sender_id === $sender->id && in_array($draft->status, ['draft', 'scheduled'], true), 403);
            }

            $message = $draft ?? new Message(['sender_id' => $sender->id]);
            $message->fill($this->messageData($data));
            $message->thread_id = $data['thread_id'] ?? $message->thread_id;
            $message->parent_id = $data['parent_id'] ?? null;
            $message->sender_id = $sender->id;
            $message->sender_email = $sender->mailAddress();
            $message->sender_name = $sender->name;
            $message->source = 'internal';
            $message->internet_message_id ??= 'u-mail-'.Str::uuid().'@'.config('external_mail.message_id_domain');
            $message->status = 'scheduled';
            $message->scheduled_send_at = $sendAt;
            $message->sent_at = null;
            $message->save();

            $this->syncRecipients($message, $sender, $data, true);
            $this->storeAttachments($message, $files);
            MailboxEntry::updateOrCreate(
                ['message_id' => $message->id, 'user_id' => $sender->id],
                ['folder' => 'scheduled', 'is_read' => true, 'trashed_at' => null, 'snoozed_until' => null],
            );

            return $message;
        });
    }

    private function messageData(array $data): array
    {
        $html = $this->sanitizer->sanitize($data['body_html'] ?? '');

        return [
            'subject' => trim($data['subject'] ?? '') ?: '(No subject)',
            'body_html' => $html,
            'body_text' => trim(html_entity_decode(strip_tags($html))),
        ];
    }

    private function resolveThread(User $sender, array $data, ?Message $draft): MailThread
    {
        $threadId = $data['thread_id'] ?? $draft?->thread_id;
        if ($threadId) {
            $thread = MailThread::findOrFail($threadId);
            $allowed = MailboxEntry::where('user_id', $sender->id)
                ->whereHas('message', fn ($query) => $query->where('thread_id', $thread->id))
                ->exists();
            abort_unless($allowed || $draft?->thread_id === $thread->id, 403);

            return $thread;
        }

        return MailThread::create([
            'created_by' => $sender->id,
            'subject' => trim($data['subject'] ?? '') ?: '(No subject)',
        ]);
    }

    private function syncRecipients(Message $message, User $sender, array $data, bool $required): void
    {
        $recipients = $this->resolveRecipients($sender, $data, $required);
        $message->recipients()->delete();
        $this->storeRecipients($message, $recipients);
    }

    private function resolveRecipients(User $sender, array $data, bool $required): Collection
    {
        $resolved = collect(['to' => collect(), 'cc' => collect(), 'bcc' => collect()]);
        foreach (['to', 'cc', 'bcc'] as $type) {
            $emails = collect(preg_split('/[,;\s]+/', strtolower($data[$type] ?? ''), -1, PREG_SPLIT_NO_EMPTY))->unique();
            if ($emails->contains('all-employees')) {
                if (! $sender->isAdmin() || $type !== 'to') {
                    throw ValidationException::withMessages([$type => 'Only administrators may use All Employees as a To recipient.']);
                }
                $emails = $emails->reject(fn ($email) => $email === 'all-employees');
                $resolved[$type] = User::where('status', 'active')
                    ->where('id', '!=', $sender->id)
                    ->get()
                    ->map(fn (User $user) => $this->recipientData($user));
            }

            if ($emails->isNotEmpty()) {
                $users = User::withTrashed()
                    ->whereIn(DB::raw('LOWER(public_email)'), $emails)
                    ->get();

                foreach ($emails as $email) {
                    $user = $users->first(fn (User $candidate) => $email === strtolower((string) $candidate->public_email));
                    if ($user) {
                        if ($user->trashed() || ! $user->isActive()) {
                            throw ValidationException::withMessages([$type => 'Inactive U-Mail recipient: '.$email]);
                        }
                        $resolved[$type]->push($this->recipientData($user));

                        continue;
                    }

                    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw ValidationException::withMessages([$type => 'Invalid recipient address: '.$email]);
                    }

                    $resolved[$type]->push([
                        'user_id' => null,
                        'email' => $email,
                        'name' => null,
                    ]);
                }

                $resolved[$type] = $resolved[$type]->unique('email')->values();
            }
        }

        if ($required && $resolved->values()->collapse()->isEmpty()) {
            throw ValidationException::withMessages(['to' => 'At least one recipient is required.']);
        }

        return $resolved;
    }

    private function recipientData(User $user): array
    {
        return [
            'user_id' => $user->id,
            'email' => strtolower($user->mailAddress()),
            'name' => $user->name,
        ];
    }

    private function storeRecipients(Message $message, Collection $recipients): void
    {
        foreach ($recipients as $type => $addresses) {
            foreach ($addresses as $address) {
                MessageRecipient::create([
                    'message_id' => $message->id,
                    'user_id' => $address['user_id'],
                    'email' => $address['email'],
                    'name' => $address['name'],
                    'type' => $type,
                ]);
            }
        }
    }

    private function storeAttachments(Message $message, array $files): void
    {
        $newSize = collect($files)->sum(fn ($file) => $file instanceof UploadedFile ? $file->getSize() : 0);
        if ($message->attachments()->sum('size') + $newSize > 25 * 1024 * 1024) {
            throw ValidationException::withMessages(['attachments' => 'Attachments may not exceed 25 MB total per message.']);
        }

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $path = $file->store('attachments/'.$message->id, 'local');
            Attachment::create([
                'message_id' => $message->id,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
            ]);
        }
    }

    public function permanentlyDeleteEntry(MailboxEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $message = $entry->message;
            $thread = $message->thread;
            $entry->delete();
            if ($message->mailboxEntries()->doesntExist() && ! in_array($message->externalDelivery?->status, ['queued', 'processing'], true)) {
                foreach ($message->attachments as $attachment) {
                    Storage::disk('local')->delete($attachment->path);
                }
                $message->delete();
                if ($thread && $thread->messages()->doesntExist()) {
                    $thread->delete();
                }
            }
        });
    }
}
