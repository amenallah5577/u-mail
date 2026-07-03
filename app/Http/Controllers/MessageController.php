<?php

namespace App\Http\Controllers;

use App\Models\MailboxEntry;
use App\Models\Message;
use App\Services\MailService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function send(Request $request, MailService $mail)
    {
        $data = $this->validateMessage($request, true);
        $draft = isset($data['draft_id']) ? Message::findOrFail($data['draft_id']) : null;
        if (! empty($data['scheduled_at']) && CarbonImmutable::parse($data['scheduled_at'])->isFuture()) {
            $message = $mail->schedule($request->user(), $data, CarbonImmutable::parse($data['scheduled_at']), $request->file('attachments', []), $draft);

            return redirect()->route('mailbox.folder', 'scheduled')->with('status', 'Message scheduled for '.$message->scheduledLabel().'.');
        }

        $message = $mail->send($request->user(), $data, $request->file('attachments', []), $draft);

        return redirect()->route('threads.show', $message->thread_id)->with(
            'status',
            $message->externalDelivery
                ? 'Message sent. Delivery to outside addresses may take a moment.'
                : 'Message sent.',
        );
    }

    public function draft(Request $request, MailService $mail)
    {
        $data = $this->validateMessage($request, false);
        $draft = isset($data['draft_id']) ? Message::findOrFail($data['draft_id']) : null;
        $message = $mail->saveDraft($request->user(), $data, $request->file('attachments', []), $draft);

        return response()->json(['id' => $message->id, 'saved_at' => $message->updated_at->toIso8601String()]);
    }

    public function showDraft(Request $request, Message $message)
    {
        abort_unless($message->sender_id === $request->user()->id && in_array($message->status, ['draft', 'scheduled'], true), 403);

        $message->load('recipients');

        return response()->json([
            'id' => $message->id,
            'thread_id' => $message->thread_id,
            'parent_id' => $message->parent_id,
            'to' => $message->recipients->where('type', 'to')->pluck('email')->join(', '),
            'cc' => $message->recipients->where('type', 'cc')->pluck('email')->join(', '),
            'bcc' => $message->recipients->where('type', 'bcc')->pluck('email')->join(', '),
            'subject' => $message->subject,
            'body' => $message->body_html,
            'scheduled_at' => $message->scheduled_send_at?->format('Y-m-d\TH:i'),
        ]);
    }

    public function discard(Request $request, Message $message, MailService $mail)
    {
        abort_unless($message->sender_id === $request->user()->id && in_array($message->status, ['draft', 'scheduled'], true), 403);
        $entry = MailboxEntry::where('message_id', $message->id)->where('user_id', $request->user()->id)->firstOrFail();
        $mail->permanentlyDeleteEntry($entry);

        return response()->noContent();
    }

    private function validateMessage(Request $request, bool $sending): array
    {
        $rules = [
            'to' => [$sending ? 'required' : 'nullable', 'string', 'max:4000'],
            'cc' => ['nullable', 'string', 'max:4000'],
            'bcc' => ['nullable', 'string', 'max:4000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string', 'max:1000000'],
            'thread_id' => ['nullable', 'integer', 'exists:mail_threads,id'],
            'parent_id' => ['nullable', 'integer', 'exists:messages,id'],
            'draft_id' => ['nullable', 'integer', 'exists:messages,id'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,jpg,jpeg,png,gif,webp,zip'],
        ];
        $data = $request->validate($rules);
        $total = collect($request->file('attachments', []))->sum(fn ($file) => $file->getSize());
        abort_if($total > 25 * 1024 * 1024, 422, 'Attachments may not exceed 25 MB total.');

        return $data;
    }
}
