<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\MailboxEntry;
use App\Models\Message;
use App\Models\User;
use App\Services\MailService;
use App\Services\UmailAgentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentRunController extends Controller
{
    public function store(Request $request, UmailAgentService $agent)
    {
        $incomingComposeContext = $request->input('compose_context');
        if (is_array($incomingComposeContext)) {
            $allowedComposeKeys = ['to', 'cc', 'subject', 'body_html', 'body_text', 'thread_id', 'parent_id', 'attachment_count'];
            if (array_diff(array_keys($incomingComposeContext), $allowedComposeKeys)) {
                return response()->json([
                    'message' => 'Unsupported composer details were provided.',
                    'errors' => [
                        'compose_context' => ['Unsupported composer details were provided.'],
                    ],
                ], 422);
            }
        }

        $data = $request->validate([
            'prompt' => ['required', 'string', 'min:3', 'max:2000'],
            'context_type' => ['nullable', Rule::in([
                'inbox', 'starred', 'sent', 'drafts', 'scheduled', 'archive', 'trash',
                'search', 'thread', 'compose', 'draft', 'admin_employees', 'account_request',
            ])],
            'context_id' => ['nullable', 'integer', 'min:1'],
            'compose_context' => ['nullable', 'array'],
            'compose_context.to' => ['nullable', 'string', 'max:4000'],
            'compose_context.cc' => ['nullable', 'string', 'max:4000'],
            'compose_context.subject' => ['nullable', 'string', 'max:255'],
            'compose_context.body_html' => ['nullable', 'string', 'max:100000'],
            'compose_context.body_text' => ['nullable', 'string', 'max:20000'],
            'compose_context.thread_id' => ['nullable', 'integer', 'min:1'],
            'compose_context.parent_id' => ['nullable', 'integer', 'min:1'],
            'compose_context.attachment_count' => ['nullable', 'integer', 'min:0', 'max:10'],
            'inline_compose' => ['nullable', 'boolean'],
        ]);

        $this->authorizeContext($request, $data['context_type'] ?? null, isset($data['context_id']) ? (int) $data['context_id'] : null);

        $run = AgentRun::create([
            'user_id' => $request->user()->id,
            'prompt' => $data['prompt'],
            'context_type' => $data['context_type'] ?? null,
            'context_id' => $data['context_id'] ?? null,
            'status' => 'queued',
        ]);

        $run->messages()->create([
            'role' => 'user',
            'content' => $data['prompt'],
            'payload' => [
                'context_type' => $run->context_type,
                'context_id' => $run->context_id,
                'compose_context' => $data['compose_context'] ?? null,
            ],
        ]);

        if ($request->boolean('inline_compose')) {
            abort_unless(isset($data['compose_context']) && is_array($data['compose_context']), 422, 'Composer context is required.');
            $run->update(['status' => 'running', 'error_text' => null]);
            $result = $agent->process($run->fresh(['user']));
            $run->update([
                'status' => 'completed',
                'result' => $result,
                'completed_at' => now(),
            ]);
            $run->refresh();

            return response()->json($this->runPayload($run), 202);
        }

        ProcessAgentRun::dispatch($run->id)->afterCommit();
        $run->refresh();

        return response()->json($this->runPayload($run), 202);
    }

    public function show(Request $request, AgentRun $agentRun)
    {
        abort_unless($agentRun->user_id === $request->user()->id, 404);

        return response()->json($this->runPayload($agentRun));
    }

    public function confirm(Request $request, AgentRun $agentRun, UmailAgentService $agent, MailService $mail)
    {
        abort_unless($agentRun->user_id === $request->user()->id, 404);
        abort_unless($agentRun->status === 'completed', 422, 'This answer is not ready yet.');

        $data = $request->validate([
            'action' => ['required', Rule::in(['create_draft', 'apply_actions'])],
        ]);

        if ($data['action'] === 'create_draft') {
            $draft = $agent->applyPreparedDraft($agentRun->load('user'), $mail);
            $draft->load('recipients');

            return response()->json([
                'status' => 'draft_created',
                'message' => 'Draft prepared. Review it before sending.',
                'draft' => [
                    'id' => $draft->id,
                    'thread_id' => $draft->thread_id,
                    'parent_id' => $draft->parent_id,
                    'to' => $draft->recipients->where('type', 'to')->pluck('email')->join(', '),
                    'cc' => $draft->recipients->where('type', 'cc')->pluck('email')->join(', '),
                    'bcc' => $draft->recipients->where('type', 'bcc')->pluck('email')->join(', '),
                    'subject' => $draft->subject,
                    'body' => $draft->body_html,
                ],
            ]);
        }

        $count = $agent->applyPreparedActions($agentRun);

        return response()->json([
            'status' => 'actions_applied',
            'message' => $count === 1 ? 'One mailbox action was applied.' : "{$count} mailbox actions were applied.",
            'applied' => $count,
        ]);
    }

    private function runPayload(AgentRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'result' => $run->result,
            'error' => $run->error_text,
            'url' => route('agent.runs.show', $run),
            'confirm_url' => route('agent.runs.confirm', $run),
            'created_at' => $run->created_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];
    }

    private function authorizeThread(Request $request, int $threadId): void
    {
        $allowed = MailboxEntry::where('user_id', $request->user()->id)
            ->whereHas('message', fn ($message) => $message->where('thread_id', $threadId))
            ->exists();

        abort_unless($allowed, 404);
    }

    private function authorizeContext(Request $request, ?string $contextType, ?int $contextId): void
    {
        if (! $contextType) {
            return;
        }

        if ($contextType === 'thread') {
            abort_unless($contextId, 422, 'A thread context is required.');
            $this->authorizeThread($request, $contextId);
            return;
        }

        if ($contextType === 'draft') {
            abort_unless($contextId, 422, 'A draft context is required.');
            $allowed = Message::whereKey($contextId)
                ->where('sender_id', $request->user()->id)
                ->whereIn('status', ['draft', 'scheduled'])
                ->exists();
            abort_unless($allowed, 404);
            return;
        }

        if (in_array($contextType, ['admin_employees', 'account_request'], true)) {
            abort_unless($request->user()->isAdmin(), 403);
            if ($contextType === 'account_request') {
                abort_unless($contextId, 422, 'An account request context is required.');
                $allowed = User::whereKey($contextId)
                    ->where('role', 'employee')
                    ->where('status', 'requested')
                    ->whereNotNull('email_verified_at')
                    ->exists();
                abort_unless($allowed, 404);
            }
        }
    }
}
