<?php

namespace Tests\Feature;

use App\Models\MailboxEntry;
use App\Models\MessageReaction;
use App\Models\User;
use App\Services\HtmlSanitizer;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GmailReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reader_renders_gmail_style_controls_and_navigation_context(): void
    {
        $sender = User::factory()->create(['name' => 'Reader Sender']);
        $recipient = User::factory()->create();
        $newer = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Newer conversation',
            'body_html' => '<p>Newer</p>',
        ]);
        $message = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Reader conversation',
            'body_html' => '<p>Reader body</p>',
        ]);
        MailboxEntry::where('message_id', $newer->id)->where('user_id', $recipient->id)->update(['updated_at' => now()->addMinute()]);

        $this->actingAs($recipient)
            ->get('/threads/'.$message->thread_id.'?folder=inbox&q=Reader&page=2')
            ->assertOk()
            ->assertSee('Conversation actions')
            ->assertSee('Add reaction')
            ->assertSee('inlineReplyCard')
            ->assertSee('folder=inbox', false)
            ->assertSee('q=Reader', false);
    }

    public function test_whole_conversation_actions_change_only_the_current_users_copies(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $first = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Action thread',
            'body_html' => '<p>First</p>',
        ]);
        $second = app(MailService::class)->send($recipient, [
            'to' => $sender->public_email,
            'thread_id' => $first->thread_id,
            'parent_id' => $first->id,
            'subject' => 'Re: Action thread',
            'body_html' => '<p>Second</p>',
        ]);

        $this->actingAs($recipient)->post('/threads/'.$first->thread_id.'/mailbox', ['action' => 'trash'])->assertRedirect();

        $this->assertSame(2, MailboxEntry::where('user_id', $recipient->id)->where('folder', 'trash')->count());
        $this->assertSame('sent', MailboxEntry::where('user_id', $sender->id)->where('message_id', $first->id)->value('folder'));
        $this->assertSame('inbox', MailboxEntry::where('user_id', $sender->id)->where('message_id', $second->id)->value('folder'));

        $this->post('/threads/'.$first->thread_id.'/mailbox', ['action' => 'restore'])->assertRedirect();
        $this->assertSame('inbox', MailboxEntry::where('user_id', $recipient->id)->where('message_id', $first->id)->value('folder'));
        $this->assertSame('sent', MailboxEntry::where('user_id', $recipient->id)->where('message_id', $second->id)->value('folder'));
    }

    public function test_participants_can_toggle_supported_reactions_but_outsiders_cannot(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();
        $message = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'React',
            'body_html' => '<p>React here</p>',
        ]);

        $this->actingAs($recipient)->post('/messages/'.$message->id.'/reaction', ['emoji' => '👍'])->assertRedirect();
        $this->assertDatabaseHas('message_reactions', ['message_id' => $message->id, 'user_id' => $recipient->id, 'emoji' => '👍']);

        $this->post('/messages/'.$message->id.'/reaction', ['emoji' => '👍'])->assertRedirect();
        $this->assertSame(0, MessageReaction::count());

        $this->post('/messages/'.$message->id.'/reaction', ['emoji' => 'not-supported'])->assertSessionHasErrors('emoji');
        $this->actingAs($outsider)->post('/messages/'.$message->id.'/reaction', ['emoji' => '👍'])->assertForbidden();
    }

    public function test_attachment_preview_is_private_and_only_supports_safe_preview_types(): void
    {
        Storage::fake('local');
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();
        $message = app(MailService::class)->send(
            $sender,
            ['to' => $recipient->public_email, 'subject' => 'Preview', 'body_html' => '<p>Files</p>'],
            [
                UploadedFile::fake()->image('photo.png'),
                UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ],
        );
        $image = $message->attachments()->where('original_name', 'photo.png')->firstOrFail();
        $text = $message->attachments()->where('original_name', 'notes.txt')->firstOrFail();

        $this->actingAs($recipient)->get('/attachments/'.$image->id.'/preview')->assertOk();
        $this->get('/attachments/'.$text->id.'/preview')->assertNotFound();
        $this->actingAs($outsider)->get('/attachments/'.$image->id.'/preview')->assertForbidden();
    }

    public function test_newsletter_html_keeps_safe_layout_and_removes_dangerous_content(): void
    {
        $clean = app(HtmlSanitizer::class)->sanitize(
            '<table style="width:100%; background-color:#fff"><tr><td><img src="https://example.test/banner.png" onerror="alert(1)"></td></tr></table>'.
            '<a href="javascript:alert(1)" style="color:#123; position:fixed">Open</a><script>alert(2)</script>',
        );

        $this->assertStringContainsString('<table', $clean);
        $this->assertStringContainsString('<img src="https://example.test/banner.png">', $clean);
        $this->assertStringContainsString('color: #123', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('position', $clean);
        $this->assertStringNotContainsString('<script', $clean);
    }
}
