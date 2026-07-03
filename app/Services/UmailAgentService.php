<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\MailboxEntry;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class UmailAgentService
{
    private const STOP_WORDS = [
        'about', 'after', 'again', 'all', 'also', 'and', 'any', 'are', 'ask', 'can', 'could', 'draft', 'email',
        'find', 'for', 'formal', 'formalize', 'from', 'have', 'last', 'mail', 'message', 'messages', 'need',
        'needs', 'please', 'polish', 'precise', 'professional', 'reply', 'search', 'show', 'summarize',
        'summary', 'that', 'the', 'their', 'this', 'thread', 'to', 'unread', 'what', 'when', 'with', 'week',
        'write',
    ];

    public function __construct(private LocalAgentEngineService $engine) {}

    public function process(AgentRun $run): array
    {
        $run->loadMissing('user');
        $context = $this->buildContext($run);

        try {
            $modelResult = $this->engine->generateJson($this->systemPrompt(), $context);
            $result = $this->normalizeResult($modelResult, $context);
        } catch (Throwable $exception) {
            $result = $this->unavailableResult($context);
        }

        $run->messages()->create([
            'role' => 'assistant',
            'content' => $result['answer'],
            'payload' => $result,
        ]);

        return $result;
    }

    public function buildContext(AgentRun $run): array
    {
        $user = $run->user;
        $context = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'mail_address' => $user->mailAddress(),
                'role' => $user->role,
            ],
            'request' => [
                'prompt' => $this->clip($run->prompt, 2000),
                'context_type' => $run->context_type,
                'context_id' => $run->context_id,
            ],
            'rules' => [
                'Use only the provided authorized mailbox context.',
                'Do not mention passwords, MFA, owner credentials, private contact emails, or hidden BCC data.',
                'Prepare drafts and action plans only; never claim that mail was sent, deleted, approved, reset, or changed.',
                'When the user asks for a draft, reply, rewrite, or formalization, return a complete prepared_draft.',
                'Drafts must be formal, precise, concise, and must preserve names, dates, amounts, commitments, and recipient intent from the provided context.',
                'If the answer needs more context, say what the user should open or search for.',
            ],
            'tools' => [],
        ];

        $composeContext = $this->composeContext($run);
        if ($composeContext) {
            $this->recordToolCall($run, 'compose_context', ['source' => 'current_composer'], $composeContext);
            $context['tools']['current_draft'] = $composeContext;
            $preSend = $this->composePreSendCheck($composeContext);
            $this->recordToolCall($run, 'compose.pre_send_check', ['source' => 'current_composer'], $preSend);
            $context['tools']['pre_send_check'] = $preSend;
        }

        $unread = $this->unreadSummary($user);
        $this->recordToolCall($run, 'unread_summary', ['limit' => 8], $unread);
        $context['tools']['unread_summary'] = $unread;

        $search = $this->mailboxSearch($user, $run->prompt);
        $this->recordToolCall($run, 'authorized_mailbox_search', ['prompt' => $this->clip($run->prompt, 500)], $search);
        $context['tools']['mailbox_search'] = $search;

        if ($run->context_type === 'thread' && $run->context_id) {
            $thread = $this->threadRead($user, (int) $run->context_id);
            $this->recordToolCall($run, 'authorized_thread_read', ['thread_id' => (int) $run->context_id], $thread);
            $context['tools']['thread_read'] = $thread;
        }

        $recipients = $this->recipientLookup($run->prompt);
        $this->recordToolCall($run, 'recipient_lookup', ['prompt' => $this->clip($run->prompt, 500)], $recipients);
        $context['tools']['recipient_lookup'] = $recipients;
        $pageContext = $this->pageContext($run);
        if ($pageContext) {
            $this->recordToolCall($run, 'page_context', [
                'context_type' => $run->context_type,
                'context_id' => $run->context_id,
            ], $pageContext);
            $context['tools']['page_context'] = $pageContext;
        }
        $context['request']['writing_task'] = $this->writingTask($run, $composeContext);
        $context['request']['agent_mode'] = $this->agentMode($run->context_type);

        return $context;
    }

    public function applyPreparedDraft(AgentRun $run, MailService $mail): Message
    {
        $draft = $run->result['prepared_draft'] ?? null;
        abort_unless(is_array($draft), 422, 'No prepared draft is available for this answer.');

        return $mail->saveDraft($run->user, [
            'to' => (string) ($draft['to'] ?? ''),
            'cc' => (string) ($draft['cc'] ?? ''),
            'bcc' => (string) ($draft['bcc'] ?? ''),
            'subject' => (string) ($draft['subject'] ?? ''),
            'body_html' => (string) ($draft['body_html'] ?? ''),
            'thread_id' => $draft['thread_id'] ?? null,
            'parent_id' => $draft['parent_id'] ?? null,
        ]);
    }

    public function applyPreparedActions(AgentRun $run): int
    {
        $actions = collect($run->result['prepared_actions'] ?? [])
            ->filter(fn ($action) => is_array($action))
            ->take(8);
        $applied = 0;

        foreach ($actions as $action) {
            $entry = MailboxEntry::where('user_id', $run->user_id)
                ->whereKey((int) ($action['entry_id'] ?? 0))
                ->first();
            if (! $entry) {
                continue;
            }

            match ($action['type'] ?? '') {
                'star' => $entry->update(['is_starred' => true]),
                'unstar' => $entry->update(['is_starred' => false]),
                'read' => $entry->update(['is_read' => true]),
                'unread' => $entry->update(['is_read' => false]),
                'archive' => $entry->folder !== 'trash' ? $entry->update(['folder' => 'archive', 'trashed_at' => null]) : null,
                default => null,
            };

            if (in_array($action['type'] ?? '', ['star', 'unstar', 'read', 'unread', 'archive'], true)) {
                $applied++;
            }
        }

        return $applied;
    }

    private function unreadSummary(User $user): array
    {
        return MailboxEntry::where('user_id', $user->id)
            ->where('folder', 'inbox')
            ->where('is_read', false)
            ->with(['labels', 'message.sender', 'message.recipients', 'message.attachments'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(fn (MailboxEntry $entry) => $this->messageSummary($entry, $user))
            ->values()
            ->all();
    }

    private function mailboxSearch(User $user, string $prompt): array
    {
        $terms = $this->searchTerms($prompt);
        if ($terms->isEmpty()) {
            return [];
        }

        return MailboxEntry::where('user_id', $user->id)
            ->with(['labels', 'message.sender', 'message.recipients', 'message.attachments'])
            ->whereHas('message', function (Builder $message) use ($terms): void {
                $message->where(function (Builder $query) use ($terms): void {
                    foreach ($terms as $term) {
                        $query->orWhere('subject', 'like', "%{$term}%")
                            ->orWhere('body_text', 'like', "%{$term}%")
                            ->orWhere('sender_name', 'like', "%{$term}%")
                            ->orWhere('sender_email', 'like', "%{$term}%")
                            ->orWhereHas('recipients', fn (Builder $recipient) => $recipient
                                ->whereIn('type', ['to', 'cc'])
                                ->where(fn (Builder $recipientSearch) => $recipientSearch
                                    ->where('name', 'like', "%{$term}%")
                                    ->orWhere('email', 'like', "%{$term}%")));
                    }
                });
            })
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->map(fn (MailboxEntry $entry) => $this->messageSummary($entry, $user))
            ->values()
            ->all();
    }

    private function threadRead(User $user, int $threadId): array
    {
        $allowed = MailboxEntry::where('user_id', $user->id)
            ->whereHas('message', fn (Builder $message) => $message->where('thread_id', $threadId))
            ->exists();

        abort_unless($allowed, 404);

        $messages = Message::where('thread_id', $threadId)
            ->where('status', 'sent')
            ->whereHas('mailboxEntries', fn (Builder $entry) => $entry->where('user_id', $user->id))
            ->with([
                'sender',
                'recipients',
                'attachments',
                'mailboxEntries' => fn ($entry) => $entry->where('user_id', $user->id)->with('labels'),
            ])
            ->orderBy('sent_at')
            ->limit(20)
            ->get();

        $summaries = $messages->map(function (Message $message) use ($user): array {
            $entry = $message->mailboxEntries->first();

            return [
                ...$this->messageSummary($entry, $user, 1200),
                'recipients' => $this->visibleRecipients($message),
            ];
        })->values();

        return [
            'thread_id' => $threadId,
            'subject' => (string) ($messages->last()?->subject ?? ''),
            'url' => route('threads.show', ['thread' => $threadId]),
            'latest_message' => $summaries->last(),
            'action_items' => $this->extractActionItems($summaries),
            'deadlines' => $this->extractDeadlines($summaries),
            'messages' => $summaries->all(),
        ];
    }

    private function recipientLookup(string $prompt): array
    {
        $terms = $this->searchTerms($prompt)->take(5);
        if ($terms->isEmpty()) {
            return [];
        }

        return User::where('status', 'active')
            ->where('role', 'employee')
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('name', 'like', "%{$term}%")
                        ->orWhere('public_email', 'like', "%{$term}%");
                }
            })
            ->orderBy('name')
            ->limit(6)
            ->get(['id', 'name', 'public_email'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'mail_address' => $user->public_email,
            ])
            ->values()
            ->all();
    }

    private function pageContext(AgentRun $run): array
    {
        $type = (string) $run->context_type;

        if (in_array($type, ['inbox', 'starred', 'sent', 'drafts', 'scheduled', 'archive', 'trash', 'search'], true)) {
            return $this->mailboxPageContext($run->user, $type, $run->prompt);
        }

        if ($type === 'draft' && $run->context_id) {
            return $this->draftContext($run->user, (int) $run->context_id);
        }

        if ($type === 'admin_employees' && $run->user->isAdmin()) {
            return $this->adminEmployeesContext();
        }

        if ($type === 'account_request' && $run->user->isAdmin() && $run->context_id) {
            return $this->accountRequestContext((int) $run->context_id);
        }

        return [];
    }

    private function mailboxPageContext(User $user, string $type, string $prompt): array
    {
        $query = MailboxEntry::where('user_id', $user->id)
            ->with(['labels', 'message.sender', 'message.recipients', 'message.attachments']);

        if ($type === 'starred') {
            $query->where('is_starred', true)->where('folder', '!=', 'trash');
        } elseif ($type === 'search') {
            $terms = $this->searchTerms($prompt);
            if ($terms->isNotEmpty()) {
                $query->whereHas('message', function (Builder $message) use ($terms): void {
                    $message->where(function (Builder $message) use ($terms): void {
                        foreach ($terms as $term) {
                            $message->orWhere('subject', 'like', "%{$term}%")
                                ->orWhere('body_text', 'like', "%{$term}%")
                                ->orWhere('sender_name', 'like', "%{$term}%")
                                ->orWhere('sender_email', 'like', "%{$term}%");
                        }
                    });
                });
            }
        } else {
            $query->where('folder', $type);
            if ($type === 'inbox') {
                $query->where(fn (Builder $entry) => $entry->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
            }
        }

        $entries = $query->orderByDesc('updated_at')->limit(12)->get();
        $messages = $entries->map(fn (MailboxEntry $entry) => $this->messageSummary($entry, $user, 520))->values();

        return [
            'context_type' => $type,
            'counts' => [
                'total_sampled' => $entries->count(),
                'unread_sampled' => $entries->where('is_read', false)->count(),
                'starred_sampled' => $entries->where('is_starred', true)->count(),
                'with_attachments_sampled' => $entries->filter(fn (MailboxEntry $entry) => $entry->message->attachments->isNotEmpty())->count(),
            ],
            'priority_scan' => $this->priorityScan($messages),
            'needs_reply' => $this->mailboxSignals($messages, 'needs_reply'),
            'urgent_messages' => $this->mailboxSignals($messages, 'urgent'),
            'deadlines' => $this->extractDeadlines($messages),
            'messages' => $messages->all(),
            'guidance' => match ($type) {
                'inbox' => 'Prioritize unread items, direct questions, urgent wording, and messages that likely need a reply.',
                'sent' => 'Look for sent messages that may need follow-up or summarization. Do not claim a reply exists unless it appears in context.',
                'drafts', 'scheduled' => 'Review unfinished outgoing messages for clarity, missing subject, missing recipient, and attachment mentions.',
                'search' => 'Explain why matching messages are relevant to the user query.',
                default => 'Summarize and organize the visible mailbox context.',
            },
        ];
    }

    private function draftContext(User $user, int $messageId): array
    {
        $message = Message::whereKey($messageId)
            ->where('sender_id', $user->id)
            ->whereIn('status', ['draft', 'scheduled'])
            ->with(['recipients', 'attachments'])
            ->first();

        if (! $message) {
            return [];
        }

        return [
            'message_id' => $message->id,
            'thread_id' => $message->thread_id,
            'status' => $message->status,
            'to' => $message->recipients->where('type', 'to')->pluck('email')->values()->all(),
            'cc' => $message->recipients->where('type', 'cc')->pluck('email')->values()->all(),
            'subject' => $this->clip($message->subject, 255),
            'body_text' => $this->clip($message->body_text, 4000),
            'attachment_count' => $message->attachments->count(),
            'pre_send_check' => $this->composePreSendCheck([
                'to' => $message->recipients->where('type', 'to')->pluck('email')->join(', '),
                'subject' => $message->subject,
                'body_text' => $message->body_text,
                'attachment_count' => $message->attachments->count(),
            ]),
        ];
    }

    private function adminEmployeesContext(): array
    {
        $requests = User::where('status', 'requested')
            ->whereNotNull('email_verified_at')
            ->orderBy('registration_requested_at')
            ->limit(8)
            ->get(['id', 'name', 'public_email', 'phone', 'registration_requested_at']);

        return [
            'counts' => [
                'awaiting_approval' => User::where('status', 'requested')->whereNotNull('email_verified_at')->count(),
                'active_employees' => User::where('role', 'employee')->where('status', 'active')->count(),
                'inactive_employees' => User::where('role', 'employee')->where('status', 'inactive')->count(),
                'administrators' => User::where('role', 'admin')->count(),
            ],
            'visible_requests' => $requests->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $this->clip($user->name, 120),
                'u_mail_address' => $user->mailAddress(),
                'phone_present' => filled($user->phone),
                'requested_at' => $user->registration_requested_at?->toIso8601String(),
            ])->values()->all(),
            'guidance' => 'Help the administrator review requests and prepare messages. Never approve, reject, delete, promote, reset MFA, or reveal credentials.',
        ];
    }

    private function accountRequestContext(int $userId): array
    {
        $requester = User::whereKey($userId)
            ->where('role', 'employee')
            ->where('status', 'requested')
            ->whereNotNull('email_verified_at')
            ->first();

        if (! $requester) {
            return [];
        }

        $nameParts = collect(preg_split('/\s+/', $requester->name, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->filter(fn ($part) => strlen($part) >= 3)
            ->take(4);
        $similar = User::whereKeyNot($requester->id)
            ->whereNotIn('status', ['requested', 'rejected', 'email_verification'])
            ->where(function (Builder $query) use ($nameParts, $requester): void {
                foreach ($nameParts as $part) {
                    $query->orWhere('name', 'like', "%{$part}%");
                }
                if (filled($requester->public_email)) {
                    $query->orWhere('public_email', 'like', '%'.strtok($requester->public_email, '@').'%');
                }
            })
            ->limit(5)
            ->get(['id', 'name', 'public_email', 'role', 'status']);

        return [
            'id' => $requester->id,
            'name' => $this->clip($requester->name, 120),
            'u_mail_address' => $requester->mailAddress(),
            'status' => $requester->status,
            'contact_email_confirmed' => (bool) $requester->email_verified_at,
            'phone_present' => filled($requester->phone),
            'requested_at' => $requester->registration_requested_at?->toIso8601String(),
            'similar_existing_accounts' => $similar->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $this->clip($user->name, 120),
                'u_mail_address' => $user->mailAddress(),
                'role' => $user->role,
                'status' => $user->status,
            ])->values()->all(),
            'guidance' => 'Summarize the request, note visible risks, and prepare approval or rejection wording only. Do not include the private contact email.',
        ];
    }

    private function composePreSendCheck(array $compose): array
    {
        $rawBody = (string) ($compose['body_text'] ?? $this->htmlToText((string) ($compose['body_html'] ?? '')));
        $body = Str::lower($rawBody);
        $bodyText = trim(strip_tags($rawBody));
        $subject = trim((string) ($compose['subject'] ?? ''));
        $to = trim((string) ($compose['to'] ?? ''));
        $attachmentCount = (int) ($compose['attachment_count'] ?? 0);
        $wordCount = str_word_count($bodyText);
        $issues = [];

        if ($to === '') {
            $issues[] = ['severity' => 'warning', 'label' => 'Missing recipient', 'detail' => 'No recipient is selected yet.'];
        }
        if ($subject === '') {
            $issues[] = ['severity' => 'warning', 'label' => 'Missing subject', 'detail' => 'A clear subject helps recipients understand the message.'];
        }
        if (preg_match('/\b(attached|attachment|find attached|pi[eè]ce jointe|joint)\b/i', $body) && $attachmentCount === 0) {
            $issues[] = ['severity' => 'warning', 'label' => 'Attachment may be missing', 'detail' => 'The message mentions an attachment, but no file is attached.'];
        }
        if ($wordCount < 4) {
            $issues[] = ['severity' => 'info', 'label' => 'Very short message', 'detail' => 'The message may need more context before sending.'];
        }
        if ($wordCount >= 8 && ! preg_match('/\b(hello|hi|dear|bonjour|salut|madame|monsieur)\b/i', $bodyText)) {
            $issues[] = ['severity' => 'info', 'label' => 'No greeting detected', 'detail' => 'A short greeting can make the message feel more professional.'];
        }
        if ($wordCount >= 12 && ! preg_match('/\b(best regards|regards|sincerely|cordially|thank you|merci|cordialement|bien à vous)\b/i', $bodyText)) {
            $issues[] = ['severity' => 'info', 'label' => 'No closing detected', 'detail' => 'A professional closing helps the message feel complete.'];
        }
        if (preg_match('/\b(you must|do it now|immediately|asap|unacceptable|bad work)\b/i', $bodyText) || substr_count($bodyText, '!') >= 3) {
            $issues[] = ['severity' => 'warning', 'label' => 'Tone may feel too direct', 'detail' => 'Consider a calmer formal wording before sending.'];
        }
        if (preg_match_all('/\b[A-Z]{4,}\b/', $rawBody, $matches) && count($matches[0]) >= 3) {
            $issues[] = ['severity' => 'warning', 'label' => 'Too much uppercase text', 'detail' => 'Uppercase wording can feel like shouting in email.'];
        }

        return [
            'attachment_count' => $attachmentCount,
            'word_count' => $wordCount,
            'issues' => $issues,
            'ready' => $issues === [],
        ];
    }

    private function messageSummary(MailboxEntry $entry, User $user, int $bodyLimit = 360): array
    {
        $message = $entry->message;

        return [
            'entry_id' => $entry->id,
            'message_id' => $message->id,
            'thread_id' => $message->thread_id,
            'folder' => $entry->folder,
            'is_read' => $entry->is_read,
            'is_starred' => $entry->is_starred,
            'subject' => $this->clip($message->subject, 180),
            'sender_name' => $this->clip($message->senderDisplayName(), 120),
            'sender_email' => $this->clip($message->senderDisplayEmail(), 180),
            'sent_at' => $message->sent_at?->toIso8601String(),
            'snippet' => $this->clip($message->body_text, $bodyLimit),
            'has_attachments' => $message->attachments->isNotEmpty(),
            'labels' => $entry->labels->pluck('name')->values()->all(),
            'url' => $message->thread_id ? route('threads.show', ['thread' => $message->thread_id, 'folder' => $entry->folder]) : null,
        ];
    }

    private function visibleRecipients(Message $message): array
    {
        return $message->recipients
            ->whereIn('type', ['to', 'cc'])
            ->map(fn ($recipient) => [
                'type' => $recipient->type,
                'name' => $recipient->name,
                'email' => $recipient->email,
            ])
            ->values()
            ->all();
    }

    private function normalizeResult(array $raw, array $context): array
    {
        $cards = $this->normalizeCards($raw['cards'] ?? []);
        if ($cards === []) {
            $cards = $this->defaultCards($context);
        }
        $draft = $this->normalizeDraft($raw['prepared_draft'] ?? null, $context);
        if (! $draft && $this->draftRequested($context)) {
            $draft = $this->fallbackDraft($context);
        }
        if ($draft && $this->draftLooksUnchanged($draft, $context)) {
            $draft = $this->fallbackDraft($context);
        }
        if ($draft && ! collect($cards)->contains(fn ($card) => ($card['type'] ?? null) === 'draft')) {
            array_unshift($cards, [
                'type' => 'draft',
                'title' => 'Formal draft prepared',
                'body' => $this->draftPreview($draft),
                'url' => null,
                'entry_id' => null,
                'thread_id' => $draft['thread_id'],
            ]);
        }

        return [
            'answer' => $this->clip($raw['answer'] ?? '', 1800) ?: ($draft
                ? 'I prepared a precise formal draft. Review it before sending.'
                : 'I reviewed the available mailbox context and prepared the results below.'),
            'cards' => $cards,
            'prepared_draft' => $draft,
            'prepared_actions' => $this->normalizeActions($raw['prepared_actions'] ?? []),
            'engine' => [
                'model' => $this->engine->modelName(),
                'local' => true,
            ],
            'completed_at' => now()->toIso8601String(),
        ];
    }

    private function unavailableResult(array $context): array
    {
        $draft = $this->draftRequested($context) ? $this->fallbackDraft($context) : null;
        $cards = $this->defaultCards($context);
        if ($draft) {
            array_unshift($cards, [
                'type' => 'draft',
                'title' => 'Draft corrected locally',
                'body' => $this->draftPreview($draft),
                'url' => null,
                'entry_id' => null,
                'thread_id' => $draft['thread_id'],
            ]);
        }

        return [
            'answer' => $draft
                ? 'I corrected the draft directly. Review it before sending.'
                : 'U-Assist is not ready right now. Mail still works normally. Make sure Ollama is running locally and the Llama 3.2 model is installed.',
            'cards' => $cards,
            'prepared_draft' => $draft,
            'prepared_actions' => [],
            'engine' => [
                'model' => $this->engine->modelName(),
                'local' => true,
                'available' => false,
            ],
            'completed_at' => now()->toIso8601String(),
        ];
    }

    private function normalizeCards(mixed $cards): array
    {
        if (! is_array($cards)) {
            return [];
        }

        return collect($cards)
            ->filter(fn ($card) => is_array($card))
            ->take(8)
            ->map(fn (array $card) => [
                'type' => preg_replace('/[^a-z0-9_-]/i', '', (string) ($card['type'] ?? 'answer')) ?: 'answer',
                'title' => $this->clip($card['title'] ?? 'Result', 140),
                'body' => $this->clip($card['body'] ?? '', 600),
                'url' => filter_var($card['url'] ?? null, FILTER_VALIDATE_URL) || str_starts_with((string) ($card['url'] ?? ''), '/')
                    ? (string) $card['url']
                    : null,
                'entry_id' => isset($card['entry_id']) ? (int) $card['entry_id'] : null,
                'thread_id' => isset($card['thread_id']) ? (int) $card['thread_id'] : null,
            ])
            ->values()
            ->all();
    }

    private function defaultCards(array $context): array
    {
        $search = collect($context['tools']['mailbox_search'] ?? []);
        $unread = collect($context['tools']['unread_summary'] ?? []);
        $thread = collect($context['tools']['thread_read']['messages'] ?? []);
        $page = collect($context['tools']['page_context']['messages'] ?? []);
        $pagePriority = collect($context['tools']['page_context']['priority_scan'] ?? [])
            ->map(fn (array $signal) => $this->signalCard($signal, 'priority_summary', 'Priority'));
        $pageNeedsReply = collect($context['tools']['page_context']['needs_reply'] ?? [])
            ->map(fn (array $signal) => $this->signalCard($signal, 'priority_summary', 'Needs reply'));
        $pageUrgent = collect($context['tools']['page_context']['urgent_messages'] ?? [])
            ->map(fn (array $signal) => $this->signalCard($signal, 'priority_summary', 'Urgent'));
        $threadActions = collect($context['tools']['thread_read']['action_items'] ?? [])
            ->map(fn (array $item) => [
                'type' => 'thread_summary',
                'title' => 'Action item',
                'body' => $this->clip(($item['text'] ?? '').' '.($item['reason'] ?? ''), 600),
                'url' => $item['url'] ?? null,
                'entry_id' => $item['entry_id'] ?? null,
                'thread_id' => $item['thread_id'] ?? null,
            ]);
        $threadDeadlines = collect($context['tools']['thread_read']['deadlines'] ?? [])
            ->map(fn (array $item) => [
                'type' => 'thread_summary',
                'title' => 'Possible deadline',
                'body' => $this->clip(($item['text'] ?? '').' '.($item['reason'] ?? ''), 600),
                'url' => $item['url'] ?? null,
                'entry_id' => $item['entry_id'] ?? null,
                'thread_id' => $item['thread_id'] ?? null,
            ]);
        $checks = collect($context['tools']['pre_send_check']['issues'] ?? [])
            ->map(fn (array $issue) => [
                'type' => 'pre_send_review',
                'title' => $issue['label'] ?? 'Review note',
                'body' => $issue['detail'] ?? '',
                'url' => null,
                'entry_id' => null,
                'thread_id' => null,
            ]);

        return $checks
            ->merge($pageNeedsReply)
            ->merge($pageUrgent)
            ->merge($pagePriority)
            ->merge($threadActions)
            ->merge($threadDeadlines)
            ->merge($page)
            ->merge($search)
            ->merge($thread)
            ->merge($unread)
            ->map(fn (array $item) => isset($item['subject']) && ! isset($item['title']) ? [
                'type' => 'message',
                'title' => $item['subject'] ?: 'Message',
                'body' => trim(($item['sender_name'] ?? 'Sender').': '.($item['snippet'] ?? '')),
                'url' => $item['url'] ?? null,
                'entry_id' => $item['entry_id'] ?? null,
                'thread_id' => $item['thread_id'] ?? null,
            ] : $item)
            ->unique(fn (array $card) => ($card['entry_id'] ?? null)
                ? ($card['type'] ?? 'card').'|entry:'.$card['entry_id']
                : ($card['type'] ?? 'card').'|'.($card['title'] ?? '').'|'.($card['body'] ?? ''))
            ->take(8)
            ->values()
            ->all();
    }

    private function priorityScan(Collection $messages): array
    {
        return $messages
            ->map(function (array $message): ?array {
                $reason = $this->priorityReason($message);
                if (! $reason) {
                    return null;
                }

                return $this->signalPayload($message, $reason);
            })
            ->filter()
            ->take(6)
            ->values()
            ->all();
    }

    private function mailboxSignals(Collection $messages, string $type): array
    {
        return $messages
            ->map(function (array $message) use ($type): ?array {
                $text = $this->messageSignalText($message);
                $reason = match ($type) {
                    'needs_reply' => $this->replyNeededReason($text),
                    'urgent' => $this->urgentReason($text),
                    default => null,
                };

                return $reason ? $this->signalPayload($message, $reason) : null;
            })
            ->filter()
            ->take(6)
            ->values()
            ->all();
    }

    private function extractActionItems(Collection $messages): array
    {
        return $messages
            ->flatMap(function (array $message): array {
                return collect($this->sentences((string) ($message['snippet'] ?? '')))
                    ->map(function (string $sentence) use ($message): ?array {
                        $text = Str::lower($sentence);
                        if (! $this->containsAny($text, [
                            'please', 'can you', 'could you', 'confirm', 'review', 'send', 'share',
                            'prepare', 'complete', 'follow up', 'reply', 'respond', 'merci de', 'veuillez',
                        ])) {
                            return null;
                        }

                        return $this->signalPayload($message, 'Contains a requested action.', $sentence);
                    })
                    ->filter()
                    ->all();
            })
            ->take(8)
            ->values()
            ->all();
    }

    private function extractDeadlines(Collection $messages): array
    {
        return $messages
            ->flatMap(function (array $message): array {
                return collect($this->sentences($this->messageSignalText($message)))
                    ->map(function (string $sentence) use ($message): ?array {
                        $reason = $this->deadlineReason($sentence);

                        return $reason ? $this->signalPayload($message, $reason, $sentence) : null;
                    })
                    ->filter()
                    ->all();
            })
            ->take(8)
            ->values()
            ->all();
    }

    private function priorityReason(array $message): ?string
    {
        $text = $this->messageSignalText($message);
        $replyReason = $this->replyNeededReason($text);
        $urgentReason = $this->urgentReason($text);

        if (! ($message['is_read'] ?? true) && $urgentReason) {
            return 'Unread and appears time-sensitive. '.$urgentReason;
        }
        if (! ($message['is_read'] ?? true) && $replyReason) {
            return 'Unread and likely needs a reply. '.$replyReason;
        }
        if (($message['is_starred'] ?? false) && $urgentReason) {
            return 'Starred and time-sensitive. '.$urgentReason;
        }
        if (($message['has_attachments'] ?? false) && $this->containsAny($text, ['invoice', 'contract', 'report', 'quote', 'devis', 'facture'])) {
            return 'Contains a business attachment that may need review.';
        }

        return null;
    }

    private function replyNeededReason(string $text): ?string
    {
        if (str_contains($text, '?')) {
            return 'Contains a direct question.';
        }
        if ($this->containsAny($text, ['please confirm', 'confirm', 'reply', 'respond', 'let me know', 'can you', 'could you', 'merci de confirmer', 'veuillez confirmer'])) {
            return 'Asks for confirmation or a response.';
        }

        return null;
    }

    private function urgentReason(string $text): ?string
    {
        if ($this->containsAny($text, ['urgent', 'asap', 'today', 'immediately', 'important', 'deadline', 'due', 'before', 'à traiter', 'prioritaire'])) {
            return 'Contains urgent or deadline wording.';
        }

        return $this->deadlineReason($text);
    }

    private function deadlineReason(string $text): ?string
    {
        if (preg_match('/\b(today|tomorrow|this week|next week|before|by|deadline|due|aujourd\'hui|demain|avant)\b/i', $text)) {
            return 'Mentions a possible deadline.';
        }
        if (preg_match('/\b\d{1,2}[\/.-]\d{1,2}(?:[\/.-]\d{2,4})?\b/', $text)) {
            return 'Mentions a date.';
        }

        return null;
    }

    private function signalPayload(array $message, string $reason, ?string $text = null): array
    {
        return [
            'entry_id' => $message['entry_id'] ?? null,
            'message_id' => $message['message_id'] ?? null,
            'thread_id' => $message['thread_id'] ?? null,
            'subject' => $message['subject'] ?? 'Message',
            'sender_name' => $message['sender_name'] ?? 'Sender',
            'snippet' => $this->clip($text ?? ($message['snippet'] ?? ''), 420),
            'reason' => $reason,
            'url' => $message['url'] ?? null,
        ];
    }

    private function signalCard(array $signal, string $type, string $prefix): array
    {
        return [
            'type' => $type,
            'title' => $prefix.': '.($signal['subject'] ?? 'Message'),
            'body' => trim(($signal['reason'] ?? '').' '.($signal['sender_name'] ?? '').': '.($signal['snippet'] ?? '')),
            'url' => $signal['url'] ?? null,
            'entry_id' => $signal['entry_id'] ?? null,
            'thread_id' => $signal['thread_id'] ?? null,
        ];
    }

    private function messageSignalText(array $message): string
    {
        return Str::lower(trim(($message['subject'] ?? '').' '.($message['snippet'] ?? '')));
    }

    private function sentences(string $text): array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($normalized === '') {
            return [];
        }

        return collect(preg_split('/(?<=[.!?])\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [$normalized])
            ->map(fn (string $sentence) => $this->clip($sentence, 320))
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeDraft(mixed $draft, array $context): ?array
    {
        if (! is_array($draft)) {
            return null;
        }

        $bodyHtml = trim((string) ($draft['body_html'] ?? ''));
        if ($bodyHtml === '' && filled($draft['body_text'] ?? null)) {
            $bodyHtml = $this->textToHtml((string) $draft['body_text']);
        }
        if ($bodyHtml === '') {
            return null;
        }

        $threadId = isset($draft['thread_id']) ? (int) $draft['thread_id'] : null;
        $parentId = isset($draft['parent_id']) ? (int) $draft['parent_id'] : null;
        $defaults = $this->draftDefaults($context);

        return [
            'to' => $this->clip($draft['to'] ?? $defaults['to'], 4000),
            'cc' => $this->clip($draft['cc'] ?? $defaults['cc'], 4000),
            'bcc' => $this->clip($draft['bcc'] ?? '', 4000),
            'subject' => $this->clip($draft['subject'] ?? '', 255) ?: $defaults['subject'],
            'body_html' => Str::limit($bodyHtml, 100000, ''),
            'thread_id' => $threadId ?: $defaults['thread_id'],
            'parent_id' => $parentId ?: $defaults['parent_id'],
        ];
    }

    private function normalizeActions(mixed $actions): array
    {
        if (! is_array($actions)) {
            return [];
        }

        return collect($actions)
            ->filter(fn ($action) => is_array($action) && in_array($action['type'] ?? '', ['star', 'unstar', 'read', 'unread', 'archive'], true))
            ->take(8)
            ->map(fn (array $action) => [
                'type' => $action['type'],
                'entry_id' => (int) ($action['entry_id'] ?? 0),
                'label' => $this->clip($action['label'] ?? '', 120),
                'reason' => $this->clip($action['reason'] ?? '', 240),
            ])
            ->filter(fn ($action) => $action['entry_id'] > 0)
            ->values()
            ->all();
    }

    private function searchTerms(string $prompt): Collection
    {
        preg_match_all('/[a-z0-9][a-z0-9@._-]{2,}/i', Str::lower($prompt), $matches);

        return collect($matches[0] ?? [])
            ->map(fn ($term) => trim($term, '._-'))
            ->filter(fn ($term) => strlen($term) >= 3 && ! in_array($term, self::STOP_WORDS, true))
            ->unique()
            ->take(8)
            ->values();
    }

    private function replySubjectFromContext(array $context): string
    {
        $composeSubject = (string) ($context['tools']['current_draft']['subject'] ?? '');
        if ($composeSubject !== '' && empty($context['tools']['thread_read'])) {
            return $composeSubject;
        }

        $subject = (string) ($context['tools']['thread_read']['subject'] ?? $composeSubject);
        if ($subject === '') {
            return 'Draft message';
        }

        return str_starts_with($subject, 'Re:') ? $subject : 'Re: '.$subject;
    }

    private function textToHtml(string $value): string
    {
        $paragraphs = preg_split('/\n{2,}/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($paragraphs)
            ->map(fn ($paragraph) => '<p>'.nl2br(e($paragraph)).'</p>')
            ->join('');
    }

    private function htmlToText(string $value): string
    {
        $value = preg_replace('/<(br|\/p|\/div|\/li)>/i', "\n", $value) ?? $value;

        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function composeContext(AgentRun $run): ?array
    {
        $message = $run->messages()->where('role', 'user')->oldest('id')->first();
        $payload = $message?->payload ?? [];
        $compose = is_array($payload) ? ($payload['compose_context'] ?? null) : null;
        if (! is_array($compose)) {
            return null;
        }

        $bodyHtml = $this->clip($compose['body_html'] ?? '', 20000);
        $bodyText = $this->clip($compose['body_text'] ?? $this->htmlToText($bodyHtml), 8000);
        $context = [
            'to' => $this->clip($compose['to'] ?? '', 4000),
            'cc' => $this->clip($compose['cc'] ?? '', 4000),
            'subject' => $this->clip($compose['subject'] ?? '', 255),
            'body_text' => $bodyText,
            'attachment_count' => (int) ($compose['attachment_count'] ?? 0),
            'thread_id' => isset($compose['thread_id']) ? (int) $compose['thread_id'] : null,
            'parent_id' => isset($compose['parent_id']) ? (int) $compose['parent_id'] : null,
        ];

        return collect($context)->filter(fn ($value) => filled($value))->isNotEmpty() ? $context : null;
    }

    private function writingTask(AgentRun $run, ?array $composeContext): array
    {
        $prompt = Str::lower($run->prompt);
        $draftIntent = $this->containsAny($prompt, ['draft', 'write', 'compose', 'reply', 'respond', 'formal', 'formalize', 'polish', 'rewrite', 'correct', 'fix']);
        $rewriteIntent = $this->containsAny($prompt, ['improve', 'formal', 'formalize', 'polish', 'rewrite', 'professional', 'shorten', 'translate', 'subject', 'correct', 'fix']);
        $reviewIntent = $this->containsAny($prompt, ['check', 'tone', 'aggressive', 'before sending', 'missing', 'review']);
        $type = 'mailbox_answer';
        if ($composeContext && $rewriteIntent) {
            $type = 'formalize_current_draft';
        } elseif ($composeContext && $reviewIntent) {
            $type = 'pre_send_review';
        } elseif ($run->context_type === 'thread' && $draftIntent) {
            $type = 'formal_reply_draft';
        } elseif ($draftIntent) {
            $type = 'formal_new_message_draft';
        }

        return [
            'type' => $type,
            'draft_required' => in_array($type, ['formalize_current_draft', 'formal_reply_draft', 'formal_new_message_draft'], true),
            'tone' => 'formal, professional, precise, clear, concise',
            'requirements' => [
                'Return a complete prepared_draft when draft_required is true.',
                'Use a greeting, direct response, necessary context, next step if useful, and a professional closing.',
                'Do not invent dates, promises, prices, approvals, attachments, or facts that are not present.',
                'For formalization, preserve the original meaning and make grammar, tone, and structure more professional.',
                'For pre_send_review, return concise cards with issues and no prepared draft unless the user explicitly asks to rewrite.',
            ],
        ];
    }

    private function draftRequested(array $context): bool
    {
        return (bool) ($context['request']['writing_task']['draft_required'] ?? false);
    }

    private function draftDefaults(array $context): array
    {
        $compose = $context['tools']['current_draft'] ?? [];
        $messages = collect($context['tools']['thread_read']['messages'] ?? []);
        $userAddress = (string) ($context['user']['mail_address'] ?? '');
        $latest = $messages->last();
        $latestIncoming = $messages
            ->reverse()
            ->first(fn ($message) => ($message['sender_email'] ?? '') !== $userAddress);

        $to = (string) ($compose['to'] ?? '');
        if ($to === '' && is_array($latestIncoming)) {
            $to = (string) ($latestIncoming['sender_email'] ?? '');
        }

        return [
            'to' => $to,
            'cc' => (string) ($compose['cc'] ?? ''),
            'subject' => $this->replySubjectFromContext($context),
            'thread_id' => (int) ($compose['thread_id'] ?? $context['tools']['thread_read']['thread_id'] ?? 0) ?: null,
            'parent_id' => (int) ($compose['parent_id'] ?? ($latest['message_id'] ?? 0)) ?: null,
        ];
    }

    private function fallbackDraft(array $context): ?array
    {
        $defaults = $this->draftDefaults($context);
        $composeBody = trim((string) ($context['tools']['current_draft']['body_text'] ?? ''));
        $prompt = Str::lower((string) ($context['request']['prompt'] ?? ''));
        $userName = $this->clip($context['user']['name'] ?? '', 120);
        $subject = $this->fallbackSubject($composeBody, $defaults['subject'], $prompt);

        $body = $composeBody !== ''
            ? $this->fallbackEditedBody($composeBody, $prompt, $userName)
            : "Hello,\n\nThank you for your message regarding {$subject}. I confirm that I have received it and will follow up with the necessary information shortly.\n\nBest regards,\n".$userName;

        return [
            'to' => $defaults['to'],
            'cc' => $defaults['cc'],
            'bcc' => '',
            'subject' => $subject,
            'body_html' => $this->textToHtml($body),
            'thread_id' => $defaults['thread_id'],
            'parent_id' => $defaults['parent_id'],
        ];
    }

    private function draftLooksUnchanged(array $draft, array $context): bool
    {
        if (! $this->draftRequested($context)) {
            return false;
        }

        $prompt = Str::lower((string) ($context['request']['prompt'] ?? ''));
        $current = $this->plainComparable((string) ($context['tools']['current_draft']['body_text'] ?? ''));
        $draftText = $this->plainComparable($this->htmlToText((string) ($draft['body_html'] ?? '')));
        $currentSubject = $this->plainComparable((string) ($context['tools']['current_draft']['subject'] ?? ''));
        $draftSubject = $this->plainComparable((string) ($draft['subject'] ?? ''));

        if ($this->containsAny($prompt, ['subject']) && ($draftSubject === '' || $draftSubject === $currentSubject || $draftSubject === 'draft message')) {
            return true;
        }

        return $current !== '' && $current === $draftText && $this->containsAny($prompt, [
            'improve', 'formal', 'formalize', 'polish', 'rewrite', 'professional', 'shorten', 'tone', 'correct', 'fix', 'check',
        ]);
    }

    private function fallbackEditedBody(string $body, string $prompt, string $userName): string
    {
        if ($this->containsAny($prompt, ['subject']) && ! $this->containsAny($prompt, ['improve', 'formal', 'rewrite', 'tone', 'check', 'shorten', 'translate'])) {
            return $body;
        }

        $text = $this->normalizeDraftText($body);
        if ($this->containsAny($prompt, ['shorten'])) {
            $text = $this->shortenDraftText($text);
        }

        $text = $this->formalizeDraftText($text);
        $text = $this->ensureGreetingAndClosing($text, $userName);

        return $text;
    }

    private function normalizeDraftText(string $body): string
    {
        $text = trim(preg_replace('/[ \t]+/', ' ', str_replace(["\r\n", "\r"], "\n", $body)) ?? $body);
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function formalizeDraftText(string $text): string
    {
        $text = preg_replace('/!{2,}/', '.', $text) ?? $text;
        $text = preg_replace('/\?{2,}/', '?', $text) ?? $text;

        $replacements = [
            '/\bhey\b/i' => 'Hello',
            '/\bhi\b/i' => 'Hello',
            '/\bpls\b|\bplz\b/i' => 'please',
            '/\bu\b/i' => 'you',
            '/\bur\b/i' => 'your',
            '/\basap\b/i' => 'as soon as possible',
            '/\bdont\b|\bdon\'t\b/i' => 'do not',
            '/\bcant\b|\bcan\'t\b/i' => 'cannot',
            '/\bwont\b|\bwon\'t\b/i' => 'will not',
            '/\bthx\b|\bthanks\b/i' => 'thank you',
            '/\bcan you\b/i' => 'could you please',
            '/\byou must\b/i' => 'please',
            '/\bdo it now\b/i' => 'please handle this as soon as possible',
            '/\bbad work\b/i' => 'this needs revision',
            '/\bunacceptable\b/i' => 'this requires attention',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        if ($this->looksMostlyUppercase($text)) {
            $text = Str::ucfirst(Str::lower($text));
        }

        $text = preg_replace('/^hello\s+(.+)/is', "Hello,\n\n$1", $text) ?? $text;

        return $this->sentenceCase($text);
    }

    private function ensureGreetingAndClosing(string $text, string $userName): string
    {
        $hasGreeting = preg_match('/^\s*(hello|dear|bonjour|salut|madame|monsieur)\b/i', $text);
        if (! $hasGreeting) {
            $text = "Hello,\n\n".$text;
        }

        $hasClosing = preg_match('/\b(best regards|regards|sincerely|thank you|merci|cordialement|bien à vous)\b/i', $text);
        if (! $hasClosing) {
            $text .= "\n\nBest regards,\n".$userName;
        }

        return trim($text);
    }

    private function shortenDraftText(string $text): string
    {
        $sentences = collect($this->sentences($text))->take(3);
        if ($sentences->isEmpty()) {
            return $text;
        }

        return $sentences->join(' ');
    }

    private function fallbackSubject(string $body, string $currentSubject, string $prompt): string
    {
        $subject = trim($currentSubject);
        if ($subject !== '' && $subject !== 'Draft message' && ! $this->containsAny($prompt, ['subject'])) {
            return $subject;
        }

        $text = Str::lower($body);
        $map = [
            'invoice' => 'Invoice Follow-up',
            'facture' => 'Invoice Follow-up',
            'report' => 'Report Request',
            'rapport' => 'Report Request',
            'meeting' => 'Meeting Follow-up',
            'réunion' => 'Meeting Follow-up',
            'document' => 'Document Request',
            'docs' => 'Document Request',
            'contract' => 'Contract Follow-up',
            'contrat' => 'Contract Follow-up',
            'confirm' => 'Confirmation Request',
            'validation' => 'Validation Request',
            'account' => 'Account Request',
        ];

        foreach ($map as $keyword => $candidate) {
            if (str_contains($text, $keyword)) {
                return $candidate;
            }
        }

        $words = collect(preg_split('/[^a-z0-9]+/i', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn ($word) => Str::lower($word))
            ->reject(fn ($word) => in_array($word, self::STOP_WORDS, true) || strlen($word) < 3)
            ->take(5);

        return $words->isNotEmpty()
            ? Str::headline($words->join(' '))
            : 'Message Follow-up';
    }

    private function looksMostlyUppercase(string $text): bool
    {
        $letters = preg_replace('/[^A-Za-z]/', '', $text) ?? '';
        if (strlen($letters) < 12) {
            return false;
        }

        $uppercase = preg_replace('/[^A-Z]/', '', $letters) ?? '';

        return strlen($uppercase) / max(strlen($letters), 1) > 0.72;
    }

    private function sentenceCase(string $text): string
    {
        return preg_replace_callback('/(^|[.!?]\s+|\n\n)([a-z])/', fn ($match) => $match[1].Str::upper($match[2]), $text) ?? $text;
    }

    private function plainComparable(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower($text)) ?? '');
    }

    private function draftPreview(array $draft): string
    {
        $text = $this->htmlToText((string) ($draft['body_html'] ?? ''));
        $to = trim((string) ($draft['to'] ?? ''));
        $prefix = $to !== '' ? "To {$to}. " : '';

        return $prefix.$this->clip($text, 260);
    }

    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function agentMode(?string $contextType): string
    {
        return match ($contextType) {
            'inbox' => 'inbox_prioritization',
            'thread' => 'thread_operator',
            'compose', 'draft' => 'compose_assistant',
            'search' => 'search_assistant',
            'drafts', 'scheduled' => 'draft_review',
            'sent' => 'sent_follow_up_review',
            'admin_employees', 'account_request' => 'admin_review',
            default => 'mailbox_operator',
        };
    }

    private function clip(mixed $value, int $limit): string
    {
        return Str::limit(trim((string) $value), $limit, '');
    }

    private function recordToolCall(AgentRun $run, string $name, array $input, array $output): void
    {
        $run->toolCalls()->create([
            'name' => $name,
            'input' => $input,
            'output' => $output,
            'status' => 'completed',
        ]);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are U-Assist, a private context-aware mailbox operator for UTICA's U-Mail application.

Return only valid JSON. Do not wrap the JSON in Markdown.

Use only the authorized tool results in the request JSON. Never infer from unavailable data. Do not reveal private contact emails, passwords, MFA data, owner credential registry data, or hidden BCC information.

You may answer questions, summarize unread mail, summarize the current page, summarize the current thread, identify matching messages, prepare a reply draft, prepare a new-message draft, review a draft before sending, translate or rewrite a draft, or prepare a reviewed action plan.

You must not send mail, delete mail, approve accounts, reset MFA, reveal credentials, or claim that you changed anything. Prepared work requires the user to confirm it in U-Mail.

Modes:
- inbox_prioritization: identify unread, urgent, and likely-reply-needed messages from authorized inbox context.
- thread_operator: summarize the thread, explain the latest message, extract actions/deadlines, find related authorized messages, or draft a reply.
- compose_assistant: rewrite, formalize, shorten, translate, generate a subject, check tone, or run pre-send review from current draft context.
- search_assistant: explain matching messages and suggest tighter searches using authorized mailbox context only.
- draft_review: review unfinished outgoing messages and prepare safer improved drafts.
- sent_follow_up_review: find sent messages that may need follow-up, without claiming replies exist unless provided.
- admin_review: summarize account requests, compare visible public account data, explain risk, and prepare approval/rejection wording only.

Drafting rules:
- If request.writing_task.draft_required is true, prepared_draft must be a complete email, not advice.
- For composer toolbar requests, act like an editor inside the message box: return the corrected final draft directly and keep explanations short.
- Use a professional formal tone suitable for UTICA workplace mail.
- Be precise: preserve all names, dates, amounts, deadlines, attachments, and decisions from the authorized context.
- Do not invent facts, approvals, dates, prices, file names, or commitments. If something is missing, write a neutral sentence or mention it in a card.
- For replies, answer the newest relevant message directly and keep the same conversation subject with "Re:".
- For formalizing an existing draft, keep the same meaning and recipients, improve grammar and structure, remove casual wording, and make the final message ready to review.
- The body_html must contain clean HTML paragraphs and may use a short list only when it makes the message clearer.
- Include a greeting and a professional closing. Sign with the user's name when appropriate.
- For pre-send review, use tools.pre_send_check issues directly and return cards. Do not create a draft unless the user asked to rewrite.
- For admin review, never include private contact email addresses and never tell the admin to approve/reject automatically.

Output schema:
{
  "answer": "clear short answer",
  "cards": [
    {"type": "answer|message|draft|action_plan|priority_summary|thread_summary|pre_send_review|admin_review", "title": "short title", "body": "useful detail", "url": "/optional-authorized-url", "entry_id": null, "thread_id": null}
  ],
  "prepared_draft": {
    "to": "comma-separated public U-Mail or email addresses",
    "cc": "",
    "bcc": "",
    "subject": "subject",
    "body_html": "<p>draft body</p>",
    "thread_id": null,
    "parent_id": null
  },
  "prepared_actions": [
    {"type": "star|unstar|read|unread|archive", "entry_id": 123, "reason": "why"}
  ]
}

Use null for prepared_draft when no draft is requested. Use [] for prepared_actions unless the user asked for a reviewed action plan.
PROMPT;
    }
}
