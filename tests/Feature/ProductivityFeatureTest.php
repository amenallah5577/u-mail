<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\MailboxEntry;
use App\Models\Message;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductivityFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_apply_filter_and_remove_mail_labels(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $message = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Finance invoice',
            'body_html' => '<p>Please review the invoice.</p>',
        ]);
        $entry = MailboxEntry::where('user_id', $recipient->id)->where('message_id', $message->id)->firstOrFail();

        $this->actingAs($recipient)->post('/labels', ['name' => 'Finance', 'color' => '#047857'])->assertSessionHas('status');
        $label = $recipient->mailLabels()->firstOrFail();

        $this->post("/mailbox/{$entry->id}/labels", ['label_id' => $label->id])->assertSessionHas('status');
        $this->assertTrue($entry->fresh()->labels->contains($label));

        $this->get('/mail/inbox?label='.$label->id)->assertOk()->assertSee('Finance invoice');

        $this->delete("/mailbox/{$entry->id}/labels/{$label->id}")->assertSessionHas('status');
        $this->assertFalse($entry->fresh()->labels->contains($label));
    }

    public function test_snoozed_mail_is_hidden_from_inbox_until_unsnoozed(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $message = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Tomorrow follow up',
            'body_html' => '<p>Remind me tomorrow.</p>',
        ]);
        $entry = MailboxEntry::where('user_id', $recipient->id)->where('message_id', $message->id)->firstOrFail();

        $this->actingAs($recipient)->get('/')
            ->assertOk()
            ->assertSee('Snooze until')
            ->assertSee('Later today')
            ->assertSee('Set custom snooze');

        $this->post("/mailbox/{$entry->id}", [
            'action' => 'snooze',
            'snoozed_until' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertSessionHas('status');

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Tomorrow follow up');

        $this->post("/mailbox/{$entry->id}", ['action' => 'unsnooze'])->assertSessionHas('status');
        $this->get('/')->assertOk()->assertSee('Tomorrow follow up');
    }

    public function test_scheduled_send_queues_and_sends_due_messages(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $this->actingAs($sender)->post('/messages/send', [
            'to' => $recipient->public_email,
            'subject' => 'Scheduled update',
            'body_html' => '<p>Send later.</p>',
            'scheduled_at' => now()->addHour()->format('Y-m-d H:i:s'),
        ])->assertRedirect('/mail/scheduled');

        $message = Message::where('subject', 'Scheduled update')->firstOrFail();
        $this->assertSame('scheduled', $message->status);
        $this->assertDatabaseHas('mailbox_entries', ['message_id' => $message->id, 'user_id' => $sender->id, 'folder' => 'scheduled']);

        $message->update(['scheduled_send_at' => now()->subMinute()]);
        $this->artisan('mail:send-scheduled')->assertExitCode(0);

        $this->assertSame('sent', $message->fresh()->status);
        $this->assertDatabaseHas('mailbox_entries', ['message_id' => $message->id, 'user_id' => $recipient->id, 'folder' => 'inbox']);
    }

    public function test_templates_and_directory_keep_private_contact_email_hidden_and_search_only(): void
    {
        $user = User::factory()->create(['email' => 'private@example.net', 'public_email' => 'worker@u-mail.local']);
        $coworker = User::factory()->create(['name' => 'Coworker One', 'email' => 'hidden@example.net', 'public_email' => 'coworker@u-mail.local', 'phone' => '+216 11 222 333']);
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin Two', 'email' => 'admin-private@example.net', 'public_email' => 'admin@u-mail.local']);

        $this->actingAs($user)->post('/templates', [
            'name' => 'Approval reply',
            'subject' => 'Approved',
            'body_html' => '<p>Approved, thank you.</p>',
        ])->assertSessionHas('status');

        $this->get('/templates')->assertOk()->assertSee('Approval reply')->assertSee('Approved');
        $this->get('/directory')
            ->assertOk()
            ->assertSee('Search required')
            ->assertDontSee('Coworker One')
            ->assertDontSee('Admin Two');
        $this->get('/directory?q=Coworker')
            ->assertOk()
            ->assertSee('Coworker One')
            ->assertSee('coworker@u-mail.local')
            ->assertDontSee('hidden@example.net')
            ->assertDontSee('+216 11 222 333');
        $this->get('/directory?q=Admin')
            ->assertOk()
            ->assertDontSee('Admin Two')
            ->assertDontSee('admin@u-mail.local')
            ->assertDontSee('admin-private@example.net');
        $this->getJson('/directory?q=Coworker')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.email', 'coworker@u-mail.local');
        $this->getJson('/directory?q=C')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_u_assist_replaces_old_passive_ai_surfaces(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();

        $this->actingAs($admin)->patch('/admin/ai-settings', [
            'enabled' => '1',
            'local_endpoint' => 'http://127.0.0.1:11434',
            'local_model' => 'llama3.2:latest',
        ])->assertSessionHas('status');
        $this->assertTrue(AiSetting::current()->enabled);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('U-Assist')
            ->assertSee('data-agent-run-url', false)
            ->assertSee('data-agent-context-type="inbox"', false)
            ->assertSee('Search mail, people, or subjects', false)
            ->assertDontSee('data-search-agent', false)
            ->assertSee('Summarize unread')
            ->assertDontSee('Suggested continuation')
            ->assertDontSee('Cleaner version available')
            ->assertDontSee('data-inline-completion-url', false)
            ->assertDontSee('data-draft-polish-url', false);

        $this->postJson('/writing/inline-completion', ['body' => 'hello'])->assertNotFound();
        $this->postJson('/writing/draft-polish', ['body' => 'hello'])->assertNotFound();
        $this->postJson('/ai/compose-suggestion', ['body' => 'hello'])->assertNotFound();
        $this->postJson('/ai/mail-insights', ['context' => 'thread'])->assertNotFound();
    }

    public function test_agent_search_uses_only_signed_in_users_mailbox(): void
    {
        $sender = User::factory()->create(['name' => 'Sender One']);
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();
        app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Finance invoice',
            'body_html' => '<p>Please review the invoice total.</p>',
        ]);
        app(MailService::class)->send($sender, [
            'to' => $outsider->public_email,
            'subject' => 'Secret payroll',
            'body_html' => '<p>Private outsider-only content.</p>',
        ]);

        AiSetting::current()->update([
            'enabled' => true,
            'provider' => 'local',
            'local_endpoint' => 'http://127.0.0.1:11434',
            'local_model' => 'llama3.2:latest',
        ]);

        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response([
                'response' => json_encode([
                    'answer' => 'I found the invoice message.',
                    'cards' => [[
                        'type' => 'message',
                        'title' => 'Finance invoice',
                        'body' => 'Please review the invoice total.',
                    ]],
                    'prepared_draft' => null,
                    'prepared_actions' => [],
                ]),
            ]),
        ]);

        $this->actingAs($recipient)->postJson('/agent/runs', [
            'prompt' => 'Find the invoice email',
        ])->assertAccepted()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.answer', 'I found the invoice message.')
            ->assertJsonPath('result.cards.0.title', 'Finance invoice');

        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:11434/api/generate'
            && str_contains($request->body(), 'Finance invoice')
            && ! str_contains($request->body(), 'Secret payroll')
            && ! str_contains($request->body(), 'Private outsider-only content'));
    }

    public function test_agent_reads_open_threads_only_when_authorized(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();
        $message = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Meeting follow up',
            'body_html' => '<p>Please confirm the next step.</p>',
        ]);

        AiSetting::current()->update([
            'enabled' => true,
            'provider' => 'local',
            'local_endpoint' => 'http://127.0.0.1:11434',
            'local_model' => 'llama3.2:latest',
        ]);

        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response([
                'response' => json_encode([
                    'answer' => 'The thread needs confirmation.',
                    'cards' => [[
                        'type' => 'answer',
                        'title' => 'Thread summary',
                        'body' => 'A follow-up needs confirmation.',
                    ]],
                    'prepared_draft' => null,
                    'prepared_actions' => [],
                ]),
            ]),
        ]);

        $this->actingAs($recipient)->postJson('/agent/runs', [
            'prompt' => 'Summarize this thread',
            'context_type' => 'thread',
            'context_id' => $message->thread_id,
        ])->assertAccepted()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.cards.0.title', 'Thread summary');

        $this->actingAs($outsider)->postJson('/agent/runs', [
            'prompt' => 'Summarize this thread',
            'context_type' => 'thread',
            'context_id' => $message->thread_id,
        ])->assertNotFound();
    }

    public function test_u_assist_contextual_actions_render_on_thread_and_admin_pages(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $requester = User::factory()->create([
            'status' => 'requested',
            'role' => 'employee',
            'email_verified_at' => now(),
            'registration_requested_at' => now(),
        ]);
        $message = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Thread actions',
            'body_html' => '<p>Please review this.</p>',
        ]);

        $this->actingAs($recipient)->get('/threads/'.$message->thread_id)
            ->assertOk()
            ->assertSee('U-Assist')
            ->assertSee('data-agent-context-type="thread"', false)
            ->assertSee('Draft reply')
            ->assertSee('Action items')
            ->assertSee('Deadlines')
            ->assertSee('Translate');

        $this->actingAs($admin)->get('/admin/employees')
            ->assertOk()
            ->assertSee('U-Assist review')
            ->assertSee('data-agent-context-type="admin_employees"', false)
            ->assertSee('data-agent-context-type="account_request"', false)
            ->assertSee('data-agent-context-id="'.$requester->id.'"', false);
    }

    public function test_agent_folder_context_uses_only_signed_in_users_mailbox(): void
    {
        $sender = User::factory()->create(['name' => 'Manager']);
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();
        app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Visible urgent question',
            'body_html' => '<p>Can you answer today?</p>',
        ]);
        app(MailService::class)->send($sender, [
            'to' => $outsider->public_email,
            'subject' => 'Invisible confidential question',
            'body_html' => '<p>Do not expose this.</p>',
        ]);

        AiSetting::current()->update([
            'enabled' => true,
            'provider' => 'local',
            'local_endpoint' => 'http://127.0.0.1:11434',
            'local_model' => 'llama3.2:latest',
        ]);

        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response([
                'response' => json_encode([
                    'answer' => 'One visible message likely needs a reply.',
                    'cards' => [],
                    'prepared_draft' => null,
                    'prepared_actions' => [],
                ]),
            ]),
        ]);

        $this->actingAs($recipient)->postJson('/agent/runs', [
            'prompt' => 'Which inbox messages need a reply?',
            'context_type' => 'inbox',
        ])->assertAccepted()
            ->assertJsonPath('status', 'completed');

        Http::assertSent(fn ($request) => str_contains($request->body(), 'Visible urgent question')
            && str_contains($request->body(), 'inbox_prioritization')
            && str_contains($request->body(), 'needs_reply')
            && str_contains($request->body(), 'urgent_messages')
            && ! str_contains($request->body(), 'Invisible confidential question')
            && ! str_contains($request->body(), 'Do not expose this'));
    }

    public function test_agent_inbox_context_includes_priority_and_reply_signals(): void
    {
        $manager = User::factory()->create(['name' => 'Manager']);
        $recipient = User::factory()->create();
        app(MailService::class)->send($manager, [
            'to' => $recipient->public_email,
            'subject' => 'Urgent contract confirmation',
            'body_html' => '<p>Please confirm today before 12/07/2026?</p>',
        ]);

        AiSetting::current()->update([
            'enabled' => true,
            'provider' => 'local',
            'local_endpoint' => 'http://127.0.0.1:11434',
            'local_model' => 'llama3.2:latest',
        ]);

        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response([
                'response' => json_encode([
                    'answer' => 'The contract confirmation needs attention.',
                    'cards' => [],
                    'prepared_draft' => null,
                    'prepared_actions' => [],
                ]),
            ]),
        ]);

        $this->actingAs($recipient)->postJson('/agent/runs', [
            'prompt' => 'What urgent inbox messages need a reply?',
            'context_type' => 'inbox',
        ])->assertAccepted()
            ->assertJsonPath('status', 'completed');

        Http::assertSent(fn ($request) => str_contains($request->body(), 'Urgent contract confirmation')
            && str_contains($request->body(), 'priority_scan')
            && str_contains($request->body(), 'needs_reply')
            && str_contains($request->body(), 'urgent_messages')
            && str_contains($request->body(), '12\\/07\\/2026'));
    }

    public function test_agent_thread_context_includes_latest_message_actions_and_deadlines(): void
    {
        $sender = User::factory()->create(['name' => 'Project Lead']);
        $recipient = User::factory()->create();
        $message = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Project validation',
            'body_html' => '<p>Please review the attached validation note and confirm before 15/07/2026.</p>',
        ]);

        AiSetting::current()->update([
            'enabled' => true,
            'provider' => 'local',
            'local_endpoint' => 'http://127.0.0.1:11434',
            'local_model' => 'llama3.2:latest',
        ]);

        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response([
                'response' => json_encode([
                    'answer' => 'There is one action item and one deadline.',
                    'cards' => [],
                    'prepared_draft' => null,
                    'prepared_actions' => [],
                ]),
            ]),
        ]);

        $this->actingAs($recipient)->postJson('/agent/runs', [
            'prompt' => 'Find action items and deadlines in this thread.',
            'context_type' => 'thread',
            'context_id' => $message->thread_id,
        ])->assertAccepted()
            ->assertJsonPath('status', 'completed');

        Http::assertSent(fn ($request) => str_contains($request->body(), 'latest_message')
            && str_contains($request->body(), 'action_items')
            && str_contains($request->body(), 'deadlines')
            && str_contains($request->body(), '15\\/07\\/2026'));
    }

    public function test_compose_toolbar_keeps_only_core_writing_actions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Improve this email', false)
            ->assertSee('Make this draft formal', false)
            ->assertSee('Shorten this email', false)
            ->assertSee('Check this email before sending', false)
            ->assertDontSee('Translate this email to French', false)
            ->assertDontSee('Translate this email to English', false)
            ->assertDontSee('Generate a clear professional subject', false)
            ->assertDontSee('Rewrite this email to correct the tone', false);
    }

    public function test_inline_compose_actions_return_corrected_draft_when_model_is_unavailable(): void
    {
        $user = User::factory()->create(['name' => 'Amina Owner']);
        AiSetting::current()->update([
            'enabled' => true,
            'provider' => 'local',
            'local_endpoint' => 'http://127.0.0.1:11434',
            'local_model' => 'llama3.2:latest',
        ]);
        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response([], 500),
        ]);

        $this->actingAs($user)->postJson('/agent/runs', [
            'prompt' => 'Make this email formal and precise.',
            'context_type' => 'compose',
            'inline_compose' => true,
            'compose_context' => [
                'to' => 'coworker@u-mail.local',
                'subject' => '',
                'body_text' => 'hey can u send docs asap!!!',
                'body_html' => '<p>hey can u send docs asap!!!</p>',
                'attachment_count' => 0,
            ],
        ])->assertAccepted()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.prepared_draft.subject', 'Document Request')
            ->assertSee('Could you please send docs as soon as possible', false)
            ->assertSee('Best regards', false)
            ->assertSee('Amina Owner', false);
    }

    public function test_agent_prepared_reply_requires_confirmation_before_creating_draft(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $message = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Partnership update',
            'body_html' => '<p>Can you send a formal reply?</p>',
        ]);

        AiSetting::current()->update([
            'enabled' => true,
            'provider' => 'local',
            'local_endpoint' => 'http://127.0.0.1:11434',
            'local_model' => 'llama3.2:latest',
        ]);

        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response([
                'response' => json_encode([
                    'answer' => 'I prepared a formal reply for review.',
                    'cards' => [[
                        'type' => 'draft',
                        'title' => 'Formal reply',
                        'body' => 'Prepared for review.',
                    ]],
                    'prepared_draft' => [
                        'to' => $sender->public_email,
                        'subject' => 'Re: Partnership update',
                        'body_html' => '<p>Hello,</p><p>Thank you for your message. I will review this and follow up shortly.</p>',
                        'thread_id' => $message->thread_id,
                        'parent_id' => $message->id,
                    ],
                    'prepared_actions' => [],
                ]),
            ]),
        ]);

        $response = $this->actingAs($recipient)->postJson('/agent/runs', [
            'prompt' => 'Draft a formal reply to this conversation',
            'context_type' => 'thread',
            'context_id' => $message->thread_id,
        ])->assertAccepted()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.prepared_draft.subject', 'Re: Partnership update');

        $this->assertDatabaseMissing('messages', [
            'sender_id' => $recipient->id,
            'subject' => 'Re: Partnership update',
            'status' => 'draft',
        ]);

        $this->postJson('/agent/runs/'.$response->json('id').'/confirm', [
            'action' => 'create_draft',
        ])->assertOk()
            ->assertJsonPath('status', 'draft_created')
            ->assertJsonPath('draft.subject', 'Re: Partnership update');

        $this->assertDatabaseHas('messages', [
            'sender_id' => $recipient->id,
            'subject' => 'Re: Partnership update',
            'status' => 'draft',
        ]);
    }

    public function test_agent_formalizes_current_composer_text_as_a_precise_prepared_draft(): void
    {
        $user = User::factory()->create();
        AiSetting::current()->update([
            'enabled' => true,
            'provider' => 'local',
            'local_endpoint' => 'http://127.0.0.1:11434',
            'local_model' => 'llama3.2:latest',
        ]);

        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response([
                'response' => json_encode([
                    'answer' => 'I prepared a precise formal version for review.',
                    'cards' => [],
                    'prepared_draft' => [
                        'body_html' => '<p>Hello,</p><p>Please send the requested documents today so that I can complete the review on time.</p><p>Best regards,<br>Amen</p>',
                    ],
                    'prepared_actions' => [],
                ]),
            ]),
        ]);

        $this->actingAs($user)->postJson('/agent/runs', [
            'prompt' => 'Make this email precise and formal.',
            'context_type' => 'compose',
            'compose_context' => [
                'to' => 'coworker@u-mail.local',
                'subject' => 'Documents needed',
                'body_html' => '<p>hey send me the docs today i need to finish</p>',
                'body_text' => 'hey send me the docs today i need to finish',
            ],
        ])->assertAccepted()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.prepared_draft.to', 'coworker@u-mail.local')
            ->assertJsonPath('result.prepared_draft.subject', 'Documents needed')
            ->assertJsonPath('result.cards.0.title', 'Formal draft prepared')
            ->assertSee('Please send the requested documents today');

        Http::assertSent(fn ($request) => str_contains($request->body(), 'hey send me the docs today i need to finish')
            && str_contains($request->body(), 'formalize_current_draft'));
    }

    public function test_agent_pre_send_review_receives_deterministic_compose_warnings(): void
    {
        $user = User::factory()->create();
        AiSetting::current()->update([
            'enabled' => true,
            'provider' => 'local',
            'local_endpoint' => 'http://127.0.0.1:11434',
            'local_model' => 'llama3.2:latest',
        ]);

        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response([
                'response' => json_encode([
                    'answer' => 'Review the warning before sending.',
                    'cards' => [[
                        'type' => 'pre_send_review',
                        'title' => 'Attachment may be missing',
                        'body' => 'The draft mentions an attachment, but no file is attached.',
                    ]],
                    'prepared_draft' => null,
                    'prepared_actions' => [],
                ]),
            ]),
        ]);

        $this->actingAs($user)->postJson('/agent/runs', [
            'prompt' => 'Check this email before sending.',
            'context_type' => 'compose',
            'compose_context' => [
                'to' => 'coworker@u-mail.local',
                'subject' => 'Report',
                'body_text' => 'Please find attached the report.',
                'attachment_count' => 0,
            ],
        ])->assertAccepted()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.cards.0.title', 'Attachment may be missing');

        Http::assertSent(fn ($request) => str_contains($request->body(), 'pre_send_check')
            && str_contains($request->body(), 'Attachment may be missing')
            && str_contains($request->body(), 'pre_send_review'));
    }

    public function test_admin_account_request_context_is_admin_only_and_hides_private_contact_email(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'employee']);
        $requester = User::factory()->create([
            'name' => 'Amina Test',
            'email' => 'private-contact@example.net',
            'public_email' => 'amina.test@u-mail.local',
            'status' => 'requested',
            'role' => 'employee',
            'email_verified_at' => now(),
            'registration_requested_at' => now(),
        ]);
        AiSetting::current()->update([
            'enabled' => true,
            'provider' => 'local',
            'local_endpoint' => 'http://127.0.0.1:11434',
            'local_model' => 'llama3.2:latest',
        ]);

        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response([
                'response' => json_encode([
                    'answer' => 'Request reviewed.',
                    'cards' => [[
                        'type' => 'admin_review',
                        'title' => 'Request summary',
                        'body' => 'Verified requester with generated U-Mail address.',
                    ]],
                    'prepared_draft' => null,
                    'prepared_actions' => [],
                ]),
            ]),
        ]);

        $this->actingAs($employee)->postJson('/agent/runs', [
            'prompt' => 'Review this account request.',
            'context_type' => 'account_request',
            'context_id' => $requester->id,
        ])->assertForbidden();

        $this->actingAs($admin)->postJson('/agent/runs', [
            'prompt' => 'Review this account request.',
            'context_type' => 'account_request',
            'context_id' => $requester->id,
        ])->assertAccepted()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.cards.0.type', 'admin_review');

        Http::assertSent(fn ($request) => str_contains($request->body(), 'amina.test@u-mail.local')
            && str_contains($request->body(), 'admin_review')
            && ! str_contains($request->body(), 'private-contact@example.net'));
    }

    public function test_agent_compose_context_rejects_bcc_before_reaching_model(): void
    {
        $user = User::factory()->create();
        AiSetting::current()->update([
            'enabled' => true,
            'provider' => 'local',
            'local_endpoint' => 'http://127.0.0.1:11434',
            'local_model' => 'llama3.2:latest',
        ]);

        $this->actingAs($user)->postJson('/agent/runs', [
            'prompt' => 'Make this email formal.',
            'context_type' => 'compose',
            'compose_context' => [
                'to' => 'coworker@u-mail.local',
                'bcc' => 'hidden@example.net',
                'body_text' => 'hello',
            ],
        ])->assertUnprocessable()
            ->assertSee('Unsupported composer details were provided');

        $this->assertDatabaseMissing('agent_runs', [
            'user_id' => $user->id,
            'prompt' => 'Make this email formal.',
        ]);
    }

    public function test_agent_returns_friendly_unavailable_state_when_ollama_fails(): void
    {
        $user = User::factory()->create();
        AiSetting::current()->update([
            'enabled' => true,
            'provider' => 'local',
            'local_endpoint' => 'http://127.0.0.1:11434',
            'local_model' => 'llama3.2:latest',
        ]);

        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response([], 500),
        ]);

        $this->actingAs($user)->postJson('/agent/runs', [
            'prompt' => 'What needs attention?',
        ])->assertAccepted()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.engine.available', false)
            ->assertSee('U-Assist is not ready right now');
    }

    public function test_drafts_are_loaded_lazily_without_embedding_full_body_in_mailbox(): void
    {
        $user = User::factory()->create();
        $marker = 'SECRET_DRAFT_BODY_MARKER';
        $draft = app(MailService::class)->saveDraft($user, [
            'to' => 'coworker@u-mail.local',
            'subject' => 'Lazy draft',
            'body_html' => '<p>Short visible text.</p><p>'.str_repeat('padding ', 30).$marker.'</p>',
        ]);
        AiSetting::current()->update([
            'enabled' => true,
            'provider' => 'local',
            'local_endpoint' => 'http://127.0.0.1:11434',
            'local_model' => 'llama3.2',
        ]);
        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response(['response' => 'Should not be used']),
        ]);

        $this->actingAs($user)->get('/mail/drafts')
            ->assertOk()
            ->assertSee('data-draft-url', false)
            ->assertSee('Improve draft')
            ->assertSee('data-agent-context-type="draft"', false)
            ->assertSee('data-agent-context-id="'.$draft->id.'"', false)
            ->assertDontSee('AI mail briefing')
            ->assertDontSee('data-ai-insights', false)
            ->assertDontSee('data-draft="{', false)
            ->assertDontSee($marker);
        Http::assertNothingSent();

        $this->getJson("/messages/{$draft->id}/draft")
            ->assertOk()
            ->assertJsonPath('id', $draft->id)
            ->assertJsonPath('subject', 'Lazy draft')
            ->assertSee($marker);
    }

}
