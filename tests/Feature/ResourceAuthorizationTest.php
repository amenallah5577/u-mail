<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\MailboxEntry;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_read_mail_attachments_or_agent_runs(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        Storage::fake('local');
        $message = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email, 'subject' => 'Private', 'body_html' => '<p>Private</p>',
        ], [UploadedFile::fake()->create('report.pdf', 1, 'application/pdf')]);
        $run = AgentRun::create(['user_id' => $recipient->id, 'prompt' => 'Summarize', 'status' => 'completed']);
        $attachment = $message->attachments()->firstOrFail();

        foreach (['/threads/'.$message->thread_id, '/attachments/'.$attachment->id,
            '/attachments/'.$attachment->id.'/preview', '/agent/runs/'.$run->id] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->post('/agent/runs', ['prompt' => 'Summarize'])->assertRedirect('/login');
        $this->post('/agent/runs/'.$run->id.'/confirm', ['action' => 'apply_actions'])->assertRedirect('/login');
    }

    public function test_admins_and_outsiders_cannot_read_or_mutate_another_mailbox(): void
    {
        Storage::fake('local');
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $message = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email, 'subject' => 'Private', 'body_html' => '<p>Private</p>',
        ], [UploadedFile::fake()->create('report.pdf', 1, 'application/pdf')]);
        $attachment = $message->attachments()->firstOrFail();
        $entry = MailboxEntry::where('user_id', $recipient->id)->firstOrFail();

        foreach (['employee', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/threads/'.$message->thread_id)->assertNotFound();
            $this->post('/mailbox/'.$entry->id, ['action' => 'trash'])->assertForbidden();
            $this->post('/threads/'.$message->thread_id.'/mailbox', ['action' => 'trash'])->assertNotFound();
            $this->get('/attachments/'.$attachment->id)->assertForbidden();
            $this->get('/attachments/'.$attachment->id.'/preview')->assertForbidden();
        }
        $this->assertSame('inbox', $entry->fresh()->folder);
        $this->actingAs($recipient)->get('/attachments/'.$attachment->id)->assertOk();
        $this->post('/mailbox/'.$entry->id, ['action' => 'star'])->assertRedirect();
        $this->assertTrue($entry->fresh()->is_starred);
    }

    public function test_another_users_draft_cannot_be_read_saved_sent_or_deleted(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $draft = app(MailService::class)->saveDraft($owner, ['subject' => 'Private draft', 'body_html' => '<p>Private</p>']);
        $this->actingAs($outsider);
        $this->get('/messages/'.$draft->id.'/draft')->assertForbidden();
        $this->delete('/messages/'.$draft->id.'/draft')->assertForbidden();
        $this->post('/messages/draft', ['draft_id' => $draft->id, 'subject' => 'Tampered'])->assertForbidden();
        $this->post('/messages/send', ['draft_id' => $draft->id, 'to' => $owner->public_email])->assertForbidden();
        $this->assertSame('Private draft', $draft->fresh()->subject);
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_foreign_thread_cannot_be_attached_to_a_draft_or_scheduled_message(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();
        $message = app(MailService::class)->send($sender, ['to' => $recipient->public_email, 'subject' => 'Private']);
        $this->actingAs($outsider);
        $payload = ['thread_id' => $message->thread_id, 'parent_id' => $message->id,
            'to' => $sender->public_email, 'subject' => 'Injected'];
        $this->post('/messages/draft', $payload)->assertForbidden();
        $this->post('/messages/send', $payload + ['scheduled_at' => now()->addHour()->toIso8601String()])->assertForbidden();
        $this->assertDatabaseMissing('messages', ['sender_id' => $outsider->id]);
    }

    public function test_another_users_agent_run_cannot_be_read_or_confirmed(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $run = AgentRun::create(['user_id' => $owner->id, 'prompt' => 'Prepare reply', 'status' => 'completed',
            'result' => ['prepared_draft' => ['subject' => 'Private draft', 'body_html' => '<p>Private</p>']]]);
        $this->actingAs($outsider)->getJson('/agent/runs/'.$run->id)->assertNotFound();
        foreach (['create_draft', 'apply_actions'] as $action) {
            $this->postJson('/agent/runs/'.$run->id.'/confirm', ['action' => $action])->assertNotFound();
        }
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_ai_draft_confirmation_rejects_foreign_reply_references(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();
        $message = app(MailService::class)->send($sender, ['to' => $recipient->public_email, 'subject' => 'Private']);
        $run = AgentRun::create(['user_id' => $outsider->id, 'prompt' => 'Reply', 'status' => 'completed',
            'result' => ['prepared_draft' => ['subject' => 'Injected', 'thread_id' => $message->thread_id, 'parent_id' => $message->id]]]);
        $this->actingAs($outsider)->postJson('/agent/runs/'.$run->id.'/confirm', ['action' => 'create_draft'])->assertForbidden();
        $this->assertDatabaseMissing('messages', ['sender_id' => $outsider->id]);
    }

    public function test_authorized_reply_draft_preserves_context_and_can_be_sent(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $mail = app(MailService::class);
        $message = $mail->send($sender, ['to' => $recipient->public_email, 'subject' => 'Conversation']);
        $draft = $mail->saveDraft($recipient, ['to' => $sender->public_email,
            'thread_id' => $message->thread_id, 'parent_id' => $message->id, 'subject' => 'Reply']);
        $this->assertSame($message->thread_id, $draft->thread_id);
        $this->assertSame($message->id, $draft->parent_id);
        $sent = $mail->send($recipient, ['to' => $sender->public_email, 'subject' => 'Reply'], [], $draft);
        $this->assertSame($message->thread_id, $sent->thread_id);
        $this->assertSame($message->id, $sent->parent_id);
        $this->assertSame('sent', $sent->status);
    }

    public function test_agent_actions_recheck_entry_ownership_at_confirmation(): void
    {
        $sender = User::factory()->create();
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $message = app(MailService::class)->send($sender, ['to' => $owner->public_email, 'subject' => 'Private']);
        $entry = MailboxEntry::where('user_id', $owner->id)->firstOrFail();
        $run = AgentRun::create(['user_id' => $outsider->id, 'prompt' => 'Archive', 'status' => 'completed',
            'result' => ['prepared_actions' => [['type' => 'archive', 'entry_id' => $entry->id]]]]);
        $this->actingAs($outsider)->postJson('/agent/runs/'.$run->id.'/confirm', ['action' => 'apply_actions'])
            ->assertOk()->assertJsonPath('applied', 0);
        $this->assertSame('inbox', $entry->fresh()->folder);

        $run->update(['user_id' => $owner->id]);
        $this->actingAs($owner)->postJson('/agent/runs/'.$run->id.'/confirm', ['action' => 'apply_actions'])
            ->assertOk()->assertJsonPath('applied', 1);
        $this->assertSame('archive', $entry->fresh()->folder);
    }

    public function test_agent_draft_context_cannot_read_another_users_draft(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $draft = app(MailService::class)->saveDraft($owner, ['subject' => 'Private draft']);
        $this->actingAs(User::factory()->create())->postJson('/agent/runs', [
            'prompt' => 'Rewrite this draft', 'context_type' => 'draft', 'context_id' => $draft->id,
        ])->assertNotFound();
        $this->assertDatabaseCount('agent_runs', 0);
        Http::assertNothingSent();
    }
}
