<?php

namespace App\Http\Controllers;

use App\Models\MailboxEntry;
use App\Models\MailThread;
use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MailboxController extends Controller
{
    public function index(Request $request, string $folder = 'inbox')
    {
        abort_unless(in_array($folder, ['inbox', 'starred', 'sent', 'drafts', 'scheduled', 'archive', 'trash']), 404);
        $filters = $this->mailFilters($request);
        $entries = $this->baseQuery($request);
        $this->applyFolder($entries, $folder);
        $this->applyFilters($entries, $filters, $request->user()->id);
        $paginated = $entries->orderByDesc('updated_at')->paginate(30)->withQueryString();

        return view('mailbox.index', [
            'folder' => $folder,
            'entries' => $paginated,
            'mailFilters' => $filters,
            'filterQuery' => $this->filterQuery($filters),
            'activeFilters' => $this->activeFilterChips($filters, $folder),
        ]);
    }

    public function show(Request $request, MailThread $thread)
    {
        $folder = in_array($request->query('folder'), ['inbox', 'starred', 'sent', 'drafts', 'scheduled', 'archive', 'trash'], true)
            ? $request->query('folder')
            : 'inbox';
        $filters = $this->mailFilters($request);
        $page = max(1, (int) $request->query('page', 1));
        $messages = $thread->messages()
            ->where('status', 'sent')
            ->whereHas('mailboxEntries', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with([
                'sender',
                'recipients.user',
                'attachments',
                'reactions.user',
                'mailboxEntries' => fn ($query) => $query->where('user_id', $request->user()->id),
            ])
            ->orderBy('sent_at')
            ->get();
        abort_if($messages->isEmpty(), 404);

        MailboxEntry::where('user_id', $request->user()->id)
            ->whereIn('message_id', $messages->pluck('id'))
            ->update(['is_read' => true]);

        $contextEntries = $this->baseQuery($request);
        $this->applyFolder($contextEntries, $folder);
        $this->applyFilters($contextEntries, $filters, $request->user()->id);
        $threadIds = $contextEntries->orderByDesc('updated_at')->get()
            ->pluck('message.thread_id')
            ->filter()
            ->unique()
            ->values();
        $position = $threadIds->search($thread->id);
        $context = array_filter(['folder' => $folder, ...$this->filterQuery($filters), 'page' => $page > 1 ? $page : null]);
        $previousThread = $position !== false && $position > 0 ? $threadIds[$position - 1] : null;
        $nextThread = $position !== false && $position < $threadIds->count() - 1 ? $threadIds[$position + 1] : null;
        $threadEntries = $messages->pluck('mailboxEntries')->flatten();

        return view('mailbox.thread', [
            'thread' => $thread,
            'messages' => $messages,
            'folder' => $folder,
            'backUrl' => route('mailbox.folder', array_filter(['folder' => $folder, ...$this->filterQuery($filters), 'page' => $page > 1 ? $page : null])),
            'previousUrl' => $previousThread ? route('threads.show', ['thread' => $previousThread, ...$context]) : null,
            'nextUrl' => $nextThread ? route('threads.show', ['thread' => $nextThread, ...$context]) : null,
            'threadStarred' => $threadEntries->contains('is_starred', true),
            'threadTrashed' => $threadEntries->every(fn ($entry) => $entry->folder === 'trash'),
            'emojis' => config('mailbox.emojis'),
            'mailFilters' => $filters,
        ]);
    }

    public function poll(Request $request)
    {
        $data = $request->validate([
            'notification_cursor' => ['nullable', 'integer', 'min:0'],
        ]);
        $user = $request->user();
        $latestInboxEntryId = (int) MailboxEntry::where('user_id', $user->id)
            ->where('folder', 'inbox')
            ->max('id');
        $notifications = collect();
        $nextCursor = $latestInboxEntryId;

        if ($user->mail_notifications_enabled && array_key_exists('notification_cursor', $data)) {
            $entries = MailboxEntry::where('user_id', $user->id)
                ->where('folder', 'inbox')
                ->where('is_read', false)
                ->where('id', '>', (int) $data['notification_cursor'])
                ->with('message.sender')
                ->orderBy('id')
                ->limit(21)
                ->get();
            $hasMore = $entries->count() > 20;
            $entries = $entries->take(20);
            if ($hasMore && $entries->isNotEmpty()) {
                $nextCursor = (int) $entries->last()->id;
            }
            $notifications = $entries->map(fn (MailboxEntry $entry) => [
                'id' => $entry->id,
                'sender' => $entry->message->senderDisplayName(),
                'subject' => $entry->message->subject,
                'sent_at' => $entry->message->sent_at?->toIso8601String(),
                'url' => route('threads.show', ['thread' => $entry->message->thread_id, 'folder' => 'inbox']),
            ]);
        }

        return response()->json([
            'unread' => MailboxEntry::where('user_id', $user->id)->where('folder', 'inbox')->where('is_read', false)->count(),
            'latest' => MailboxEntry::where('user_id', $user->id)->max('updated_at'),
            'notification_cursor' => $nextCursor,
            'notifications' => $notifications,
        ]);
    }

    public function update(Request $request, MailboxEntry $entry, MailService $mail)
    {
        abort_unless($entry->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'action' => ['required', Rule::in(['star', 'unstar', 'read', 'unread', 'archive', 'trash', 'restore', 'delete', 'snooze', 'unsnooze'])],
            'snoozed_until' => ['nullable', 'date', 'after:now'],
        ]);

        match ($data['action']) {
            'star' => $entry->update(['is_starred' => true]),
            'unstar' => $entry->update(['is_starred' => false]),
            'read' => $entry->update(['is_read' => true]),
            'unread' => $entry->update(['is_read' => false]),
            'archive' => $entry->update(['folder' => 'archive', 'trashed_at' => null]),
            'trash' => $entry->update(['folder' => 'trash', 'trashed_at' => now()]),
            'restore' => $entry->update([
                'folder' => $entry->message->status === 'draft'
                    ? 'drafts'
                    : ($entry->message->status === 'scheduled' ? 'scheduled' : ($entry->message->sender_id === $entry->user_id ? 'sent' : 'inbox')),
                'trashed_at' => null,
            ]),
            'snooze' => $entry->update(['folder' => 'inbox', 'snoozed_until' => $data['snoozed_until'] ?? now()->addDay()]),
            'unsnooze' => $entry->update(['snoozed_until' => null]),
            'delete' => $mail->permanentlyDeleteEntry($entry),
        };

        return back()->with('status', 'Mailbox updated.');
    }

    public function updateThread(Request $request, MailThread $thread, MailService $mail)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['star', 'unstar', 'read', 'unread', 'archive', 'trash', 'restore', 'delete', 'snooze', 'unsnooze'])],
            'snoozed_until' => ['nullable', 'date', 'after:now'],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);
        $entries = MailboxEntry::where('user_id', $request->user()->id)
            ->whereHas('message', fn ($query) => $query->where('thread_id', $thread->id))
            ->with('message')
            ->get();
        abort_if($entries->isEmpty(), 404);

        match ($data['action']) {
            'star' => MailboxEntry::whereKey($entries->pluck('id'))->update(['is_starred' => true]),
            'unstar' => MailboxEntry::whereKey($entries->pluck('id'))->update(['is_starred' => false]),
            'read' => MailboxEntry::whereKey($entries->pluck('id'))->update(['is_read' => true]),
            'unread' => MailboxEntry::whereKey($entries->pluck('id'))->update(['is_read' => false]),
            'archive' => MailboxEntry::whereKey($entries->where('folder', 'inbox')->pluck('id'))->update(['folder' => 'archive', 'trashed_at' => null]),
            'trash' => MailboxEntry::whereKey($entries->pluck('id'))->update(['folder' => 'trash', 'trashed_at' => now()]),
            'restore' => $entries->each(fn ($entry) => $entry->update([
                'folder' => $entry->message->status === 'scheduled' ? 'scheduled' : ($entry->message->sender_id === $entry->user_id ? 'sent' : 'inbox'),
                'trashed_at' => null,
            ])),
            'snooze' => $entries->each(fn ($entry) => $entry->update(['folder' => 'inbox', 'snoozed_until' => $data['snoozed_until'] ?? now()->addDay()])),
            'unsnooze' => $entries->each(fn ($entry) => $entry->update(['snoozed_until' => null])),
            'delete' => $entries->each(fn ($entry) => $mail->permanentlyDeleteEntry($entry)),
        };

        $returnTo = $data['return_to'] ?? null;
        if ($returnTo && str_starts_with($returnTo, '/') && ! str_starts_with($returnTo, '//')) {
            return redirect($returnTo)->with('status', 'Conversation updated.');
        }

        return back()->with('status', 'Conversation updated.');
    }

    private function baseQuery(Request $request)
    {
        return MailboxEntry::where('user_id', $request->user()->id)
            ->with(['labels', 'message.sender', 'message.recipients.user', 'message.attachments', 'message.thread', 'message.externalDelivery']);
    }

    private function applyFolder($entries, string $folder): void
    {
        if ($folder === 'starred') {
            $entries->where('is_starred', true)->where('folder', '!=', 'trash');
        } elseif ($folder === 'scheduled') {
            $entries->where('folder', 'scheduled');
        } else {
            $entries->where('folder', $folder);
            if ($folder === 'inbox') {
                $entries->where(fn ($query) => $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
            }
        }
    }

    private function mailFilters(Request $request): array
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'string', 'max:120'],
            'to' => ['nullable', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:120'],
            'exact' => ['nullable', 'string', 'max:120'],
            'exclude' => ['nullable', 'string', 'max:120'],
            'read_status' => ['nullable', Rule::in(['any', 'read', 'unread'])],
            'starred' => ['nullable', Rule::in(['any', 'starred', 'unstarred'])],
            'attachments' => ['nullable', Rule::in(['any', 'yes', 'no'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'size_operator' => ['nullable', Rule::in(['none', 'larger', 'smaller'])],
            'size_value' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'size_unit' => ['nullable', Rule::in(['kb', 'mb'])],
            'label' => ['nullable', 'integer', 'exists:mail_labels,id'],
        ]);

        $string = fn (string $key) => trim((string) ($data[$key] ?? ''));

        return [
            'q' => $string('q'),
            'from' => $string('from'),
            'to' => $string('to'),
            'subject' => $string('subject'),
            'exact' => $string('exact'),
            'exclude' => $string('exclude'),
            'read_status' => $data['read_status'] ?? 'any',
            'starred' => $data['starred'] ?? 'any',
            'attachments' => $data['attachments'] ?? 'any',
            'date_from' => $data['date_from'] ?? '',
            'date_to' => $data['date_to'] ?? '',
            'size_operator' => $data['size_operator'] ?? 'none',
            'size_value' => $data['size_value'] ?? '',
            'size_unit' => $data['size_unit'] ?? 'kb',
            'label' => $data['label'] ?? '',
        ];
    }

    private function filterQuery(array $filters): array
    {
        if ($filters['size_value'] === '') {
            $filters['size_operator'] = 'none';
            $filters['size_unit'] = 'kb';
        }

        return collect($filters)
            ->reject(fn ($value, $key) => $value === '' || in_array([$key, $value], [
                ['read_status', 'any'],
                ['starred', 'any'],
                ['attachments', 'any'],
                ['size_operator', 'none'],
                ['size_unit', 'kb'],
                ['label', ''],
            ], true))
            ->all();
    }

    private function activeFilterChips(array $filters, string $folder): array
    {
        $labels = [
            'q' => 'Search',
            'from' => 'From',
            'to' => 'To',
            'subject' => 'Subject',
            'exact' => 'Exact',
            'exclude' => 'Excludes',
            'read_status' => 'Status',
            'starred' => 'Star',
            'attachments' => 'Files',
            'date_from' => 'After',
            'date_to' => 'Before',
            'size_value' => 'Size',
            'label' => 'Label',
        ];

        $query = $this->filterQuery($filters);

        return collect($query)
            ->filter(fn ($value, $key) => $key !== 'size_operator' && $key !== 'size_unit')
            ->map(function ($value, $key) use ($filters, $labels, $folder, $query) {
                $display = match ($key) {
                    'read_status' => $value === 'read' ? 'Read' : 'Unread',
                    'starred' => $value === 'starred' ? 'Starred' : 'Not starred',
                    'attachments' => $value === 'yes' ? 'Has attachments' : 'No attachments',
                    'size_value' => ($filters['size_operator'] === 'smaller' ? 'Smaller than ' : 'Larger than ').$value.' '.strtoupper($filters['size_unit']),
                    'label' => optional(auth()->user()?->mailLabels()->find($value))->name ?: 'Selected',
                    default => $value,
                };
                $remove = $query;
                unset($remove[$key]);
                if ($key === 'size_value') {
                    unset($remove['size_operator'], $remove['size_unit']);
                }

                return [
                    'label' => ($labels[$key] ?? ucfirst($key)).': '.$display,
                    'url' => route('mailbox.folder', ['folder' => $folder, ...$remove]),
                ];
            })
            ->values()
            ->all();
    }

    private function applyFilters($entries, array $filters, int $userId): void
    {
        if ($filters['read_status'] === 'read') {
            $entries->where('is_read', true);
        } elseif ($filters['read_status'] === 'unread') {
            $entries->where('is_read', false);
        }

        if ($filters['starred'] === 'starred') {
            $entries->where('is_starred', true);
        } elseif ($filters['starred'] === 'unstarred') {
            $entries->where('is_starred', false);
        }

        if ($filters['attachments'] === 'yes') {
            $entries->whereHas('message.attachments');
        } elseif ($filters['attachments'] === 'no') {
            $entries->whereDoesntHave('message.attachments');
        }

        if ($filters['label'] !== '') {
            $entries->whereHas('labels', fn ($query) => $query
                ->where('mail_labels.user_id', $userId)
                ->where('mail_labels.id', $filters['label']));
        }

        $entries->whereHas('message', function ($message) use ($filters, $userId) {
            if ($filters['q'] !== '') {
                $this->applyTextSearch($message, $filters['q'], $userId);
            }
            if ($filters['from'] !== '') {
                $this->applySenderSearch($message, $filters['from']);
            }
            if ($filters['to'] !== '') {
                $this->applyRecipientSearch($message, $filters['to'], $userId);
            }
            if ($filters['subject'] !== '') {
                $message->where('subject', 'like', '%'.$filters['subject'].'%');
            }
            if ($filters['exact'] !== '') {
                $message->where(function ($query) use ($filters) {
                    $query->where('subject', 'like', '%'.$filters['exact'].'%')
                        ->orWhere('body_text', 'like', '%'.$filters['exact'].'%');
                });
            }
            if ($filters['exclude'] !== '') {
                foreach (preg_split('/\s+/', $filters['exclude'], -1, PREG_SPLIT_NO_EMPTY) as $word) {
                    $message->where('subject', 'not like', '%'.$word.'%')
                        ->where('body_text', 'not like', '%'.$word.'%');
                }
            }
            if ($filters['date_from'] !== '') {
                $message->whereDate('sent_at', '>=', $filters['date_from']);
            }
            if ($filters['date_to'] !== '') {
                $message->whereDate('sent_at', '<=', $filters['date_to']);
            }
            if ($filters['size_operator'] !== 'none' && $filters['size_value'] !== '') {
                $bytes = (int) $filters['size_value'] * ($filters['size_unit'] === 'mb' ? 1024 * 1024 : 1024);
                $operator = $filters['size_operator'] === 'smaller' ? '<' : '>';
                $message->whereRaw(
                    "(LENGTH(COALESCE(body_html, '')) + LENGTH(COALESCE(body_text, '')) + COALESCE((SELECT SUM(size) FROM attachments WHERE attachments.message_id = messages.id), 0)) {$operator} ?",
                    [$bytes]
                );
            }
        });
    }

    private function applyTextSearch($message, string $search, int $userId): void
    {
        $message->where(function ($message) use ($search, $userId) {
            if (DB::connection()->getDriverName() === 'mysql') {
                $message->whereRaw('MATCH(subject, body_text) AGAINST (? IN BOOLEAN MODE)', [$search.'*']);
            } else {
                $message->where('subject', 'like', "%{$search}%")->orWhere('body_text', 'like', "%{$search}%");
            }
            $message
                ->orWhere(fn ($query) => $this->applySenderSearch($query, $search))
                ->orWhere(fn ($query) => $this->applyRecipientSearch($query, $search, $userId));
        });
    }

    private function applySenderSearch($message, string $search): void
    {
        $message->where(function ($message) use ($search) {
            $message->where('sender_name', 'like', "%{$search}%")
                ->orWhere('sender_email', 'like', "%{$search}%")
                ->orWhereHas('sender', fn ($sender) => $sender
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('public_email', 'like', "%{$search}%"));
        });
    }

    private function applyRecipientSearch($message, string $search, int $userId): void
    {
        $message->whereHas('recipients', function ($recipient) use ($search, $userId) {
            $recipient->where(function ($recipient) use ($search) {
                $recipient->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })->where(function ($recipient) use ($userId) {
                $recipient->whereIn('type', ['to', 'cc'])
                    ->orWhere('user_id', $userId);
            });
        });
    }
}
