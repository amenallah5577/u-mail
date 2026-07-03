<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\IncomingImport;
use App\Models\MailboxEntry;
use App\Models\MailThread;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\Message as ParsedMessage;

class IncomingMailService
{
    public function __construct(private HtmlSanitizer $sanitizer) {}

    public function importEml(string $raw): IncomingImport
    {
        if (strlen($raw) > config('external_mail.max_incoming_bytes')) {
            throw ValidationException::withMessages(['eml' => 'This email is too large to import.']);
        }

        $parsed = ParsedMessage::from($raw, false);
        $from = $parsed->getHeader(HeaderConsts::FROM);
        $attachments = collect($parsed->getAllAttachmentParts())->map(function ($part) {
            $filename = $part->getFilename() ?: 'attachment';
            $content = $part->getContentStream()?->getContents() ?? '';

            return [
                'name' => basename($filename),
                'mime_type' => $part->getHeaderValue(HeaderConsts::CONTENT_TYPE, 'application/octet-stream'),
                'content' => $content,
                'size' => strlen($content),
            ];
        })->all();

        return $this->import([
            'internet_message_id' => $this->normalizeMessageId($parsed->getHeaderValue(HeaderConsts::MESSAGE_ID)),
            'in_reply_to' => $this->normalizeMessageId($parsed->getHeaderValue(HeaderConsts::IN_REPLY_TO)),
            'references' => $this->messageIds((string) $parsed->getHeaderValue(HeaderConsts::REFERENCES, '')),
            'sender_email' => strtolower((string) $from?->getEmail()),
            'sender_name' => $from?->getPersonName(),
            'recipients' => $this->parsedRecipients($parsed),
            'subject' => $parsed->getSubject() ?: '(No subject)',
            'body_html' => $parsed->getHtmlContent(),
            'body_text' => $parsed->getTextContent(),
            'attachments' => $attachments,
            'raw' => $raw,
        ]);
    }

    public function simulate(array $data): IncomingImport
    {
        return $this->import([
            'internet_message_id' => $data['internet_message_id'] ?? null,
            'in_reply_to' => $data['in_reply_to'] ?? null,
            'references' => [],
            'sender_email' => strtolower($data['sender_email']),
            'sender_name' => $data['sender_name'] ?? null,
            'recipients' => preg_split('/[,;\s]+/', strtolower($data['recipients']), -1, PREG_SPLIT_NO_EMPTY),
            'subject' => $data['subject'] ?: '(No subject)',
            'body_html' => $data['body_html'] ?? null,
            'body_text' => $data['body_text'] ?? null,
            'attachments' => [],
            'raw' => null,
        ]);
    }

    public function assign(IncomingImport $import, Collection $users): void
    {
        abort_unless($import->message_id, 422, 'This message cannot be assigned.');
        foreach ($users as $user) {
            MailboxEntry::updateOrCreate(
                ['message_id' => $import->message_id, 'user_id' => $user->id],
                ['folder' => 'inbox', 'is_read' => false, 'trashed_at' => null],
            );
            MessageRecipient::firstOrCreate(
                ['message_id' => $import->message_id, 'email' => $user->public_email, 'type' => 'to'],
                ['user_id' => $user->id, 'name' => $user->name],
            );
        }
        $routed = collect($import->routed_user_ids)->merge($users->pluck('id'))->unique()->values()->all();
        $import->update(['status' => 'routed', 'routed_user_ids' => $routed, 'reason' => null]);
    }

    private function import(array $data): IncomingImport
    {
        $messageId = $this->normalizeMessageId($data['internet_message_id'] ?? null)
            ?: 'local-incoming-'.Str::uuid().'@'.config('external_mail.message_id_domain');
        if ($existing = IncomingImport::where('internet_message_id', $messageId)->first()) {
            return $existing;
        }
        if (! filter_var($data['sender_email'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['sender_email' => 'Use a valid outside sender address.']);
        }

        $recipients = collect($data['recipients'])->map(fn ($email) => strtolower(trim($email, " \t\n\r\0\x0B<>")))->filter()->unique()->values();
        $attachments = collect($data['attachments'] ?? []);
        $dangerous = $attachments->first(fn ($file) => in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), config('external_mail.dangerous_extensions'), true));
        $oversized = $attachments->sum('size') > config('external_mail.max_attachment_bytes');
        $active = User::where('status', 'active')->whereIn('public_email', $recipients)->get();
        $inactive = User::withTrashed()->whereIn('public_email', $recipients)->where(function ($query) {
            $query->where('status', '!=', 'active')->orWhereNotNull('deleted_at');
        })->exists();
        $owner = $this->owner();
        $targets = $active;
        $status = 'routed';
        $reason = null;

        if ($dangerous || $oversized) {
            $targets = collect();
            $status = 'quarantined';
            $reason = $dangerous ? 'Unsafe attachment type' : 'Attachments exceed the allowed size';
        } elseif ($active->isEmpty()) {
            $targets = $owner ? collect([$owner]) : collect();
            $status = 'owner_intake';
            $reason = $inactive ? 'Address belongs to an inactive account' : 'Address does not match an active account';
        }

        return DB::transaction(function () use ($data, $messageId, $recipients, $attachments, $targets, $status, $reason) {
            $parent = $this->findReferencedMessage($data);
            $creator = $targets->first() ?: $this->owner();
            $thread = $parent?->thread ?? MailThread::create([
                'created_by' => $creator?->id ?? User::where('status', 'active')->value('id'),
                'subject' => $data['subject'],
            ]);
            $html = $data['body_html']
                ? $this->sanitizer->sanitize($data['body_html'])
                : nl2br(e($data['body_text'] ?? ''));
            $message = null;
            if ($status !== 'quarantined') {
                $message = Message::create([
                    'thread_id' => $thread->id,
                    'sender_id' => null,
                    'sender_email' => $data['sender_email'],
                    'sender_name' => $data['sender_name'],
                    'source' => 'external',
                    'internet_message_id' => $messageId,
                    'in_reply_to' => $data['in_reply_to'] ?? null,
                    'parent_id' => $parent?->id,
                    'subject' => $data['subject'],
                    'body_html' => $html,
                    'body_text' => trim($data['body_text'] ?? html_entity_decode(strip_tags($html))),
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
                foreach ($targets as $target) {
                    MailboxEntry::create(['message_id' => $message->id, 'user_id' => $target->id, 'folder' => 'inbox', 'is_read' => false]);
                    MessageRecipient::create([
                        'message_id' => $message->id,
                        'user_id' => $target->id,
                        'email' => $target->public_email,
                        'name' => $target->name,
                        'type' => 'to',
                    ]);
                }
                foreach ($attachments as $attachment) {
                    $path = 'attachments/'.$message->id.'/'.Str::uuid().'-'.basename($attachment['name']);
                    Storage::disk('local')->put($path, $attachment['content']);
                    Attachment::create([
                        'message_id' => $message->id,
                        'original_name' => basename($attachment['name']),
                        'path' => $path,
                        'mime_type' => $attachment['mime_type'],
                        'size' => $attachment['size'],
                    ]);
                }
                $thread->update(['latest_message_at' => $message->sent_at]);
            } elseif ($thread->messages()->doesntExist()) {
                $thread->delete();
            }

            $import = IncomingImport::create([
                'internet_message_id' => $messageId,
                'sender_email' => $data['sender_email'],
                'sender_name' => $data['sender_name'],
                'recipient_addresses' => $recipients->all(),
                'subject' => $data['subject'],
                'status' => $status,
                'routed_user_ids' => $targets->pluck('id')->all(),
                'message_id' => $message?->id,
                'reason' => $reason,
            ]);
            if ($status === 'quarantined' && $data['raw']) {
                $path = 'incoming-quarantine/'.$import->id.'.eml';
                Storage::disk('local')->put($path, $data['raw']);
                $import->update(['raw_path' => $path]);
            }

            return $import;
        });
    }

    private function parsedRecipients(ParsedMessage $message): array
    {
        $addresses = collect();
        foreach ([HeaderConsts::TO, HeaderConsts::CC, HeaderConsts::BCC] as $headerName) {
            foreach ($message->getHeader($headerName)?->getAddresses() ?? [] as $address) {
                $addresses->push($address->getEmail());
            }
        }
        foreach (preg_split('/[,;\s]+/', (string) $message->getHeaderValue('Delivered-To', ''), -1, PREG_SPLIT_NO_EMPTY) as $address) {
            $addresses->push($address);
        }

        return $addresses->filter()->unique()->values()->all();
    }

    private function findReferencedMessage(array $data): ?Message
    {
        $ids = collect([$data['in_reply_to'] ?? null])->merge($data['references'] ?? [])->filter()->map(fn ($id) => $this->normalizeMessageId($id));

        return Message::whereIn('internet_message_id', $ids)->latest('id')->first();
    }

    private function messageIds(string $value): array
    {
        preg_match_all('/<([^>]+)>/', $value, $matches);

        return $matches[1] ?? [];
    }

    private function normalizeMessageId(?string $value): ?string
    {
        $value = trim((string) $value, " \t\n\r\0\x0B<>");

        return $value !== '' ? strtolower($value) : null;
    }

    private function owner(): ?User
    {
        return User::where('status', 'active')
            ->where(function ($query) {
                $query->when(config('owner.email'), fn ($q, $email) => $q->where('email', strtolower($email)))
                    ->orWhere('role', 'admin');
            })
            ->orderByRaw('CASE WHEN email = ? THEN 0 ELSE 1 END', [strtolower((string) config('owner.email'))])
            ->first();
    }
}
