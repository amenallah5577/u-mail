<?php

namespace Tests\Feature;

use App\Mail\ExternalMessageMail;
use App\Models\MailboxEntry;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_message_is_delivered_with_private_per_user_state(): void
    {
        $sender = User::factory()->create(['name' => 'Sender']);
        $recipient = User::factory()->create(['name' => 'Recipient']);
        $bcc = User::factory()->create(['name' => 'Hidden']);
        $outsider = User::factory()->create();

        $message = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'bcc' => $bcc->public_email,
            'subject' => 'Quarterly update',
            'body_html' => '<p>Hello <strong>team</strong></p><script>alert(1)</script>',
        ]);

        $this->assertSame('Hello teamalert(1)', $message->body_text);
        $this->assertStringNotContainsString('<script', $message->body_html);
        $this->assertSame($sender->public_email, $message->sender_email);
        $this->assertDatabaseHas('message_recipients', ['message_id' => $message->id, 'user_id' => $recipient->id, 'email' => $recipient->public_email]);
        $this->assertDatabaseHas('mailbox_entries', ['message_id' => $message->id, 'user_id' => $sender->id, 'folder' => 'sent']);
        $this->assertDatabaseHas('mailbox_entries', ['message_id' => $message->id, 'user_id' => $recipient->id, 'folder' => 'inbox', 'is_read' => false]);

        $this->actingAs($recipient)->get('/threads/'.$message->thread_id)
            ->assertOk()
            ->assertDontSee($bcc->public_email);
        $this->actingAs($outsider)->get('/threads/'.$message->thread_id)->assertNotFound();
    }

    public function test_only_admin_can_send_to_all_employees(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create();
        $other = User::factory()->create();

        app(MailService::class)->send($admin, ['to' => 'all-employees', 'subject' => 'Notice', 'body_html' => '<p>Notice</p>']);
        $this->assertDatabaseHas('mailbox_entries', ['user_id' => $employee->id, 'folder' => 'inbox']);
        $this->assertDatabaseHas('mailbox_entries', ['user_id' => $other->id, 'folder' => 'inbox']);

        $this->actingAs($employee)->post('/messages/send', ['to' => 'all-employees', 'subject' => 'No', 'body_html' => 'No'])
            ->assertSessionHasErrors('to');
    }

    public function test_message_can_be_sent_to_external_recipients_with_bcc_privacy(): void
    {
        Mail::fake();
        $sender = User::factory()->create(['name' => 'Sender']);

        $message = app(MailService::class)->send($sender, [
            'to' => 'outside@example.net',
            'cc' => 'copy@example.net',
            'bcc' => 'hidden@example.net',
            'subject' => 'External notice',
            'body_html' => '<p>Hello outside</p>',
        ]);

        $this->assertDatabaseHas('message_recipients', [
            'message_id' => $message->id,
            'user_id' => null,
            'email' => 'outside@example.net',
            'type' => 'to',
        ]);
        $this->assertDatabaseHas('mailbox_entries', [
            'message_id' => $message->id,
            'user_id' => $sender->id,
            'folder' => 'sent',
        ]);
        $this->assertDatabaseCount('mailbox_entries', 1);

        Mail::assertSent(ExternalMessageMail::class, function (ExternalMessageMail $mail) {
            return $mail->hasTo('outside@example.net')
                && $mail->hasCc('copy@example.net')
                && $mail->hasBcc('hidden@example.net');
        });

        $this->actingAs($sender)
            ->get('/threads/'.$message->thread_id)
            ->assertOk()
            ->assertSee('outside@example.net')
            ->assertSee('hidden@example.net');
    }

    public function test_external_message_includes_attachments(): void
    {
        Mail::fake();
        Storage::fake('local');
        $sender = User::factory()->create();

        app(MailService::class)->send(
            $sender,
            ['to' => 'outside@example.net', 'subject' => 'External file', 'body_html' => '<p>Attached</p>'],
            [UploadedFile::fake()->create('report.pdf', 100, 'application/pdf')],
        );

        Mail::assertSent(ExternalMessageMail::class, fn (ExternalMessageMail $mail) => count($mail->attachments()) === 1);
    }

    public function test_inactive_u_mail_address_is_not_treated_as_an_external_recipient(): void
    {
        Mail::fake();
        $sender = User::factory()->create();
        $inactive = User::factory()->create(['status' => 'inactive']);

        $this->actingAs($sender)->post('/messages/send', [
            'to' => $inactive->public_email,
            'subject' => 'Blocked',
            'body_html' => '<p>Blocked</p>',
        ])->assertSessionHasErrors('to');

        Mail::assertNothingSent();
    }

    public function test_draft_can_be_saved_then_sent(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $mail = app(MailService::class);
        $draft = $mail->saveDraft($sender, ['to' => $recipient->public_email, 'subject' => 'Draft', 'body_html' => '<p>Working</p>']);
        $this->assertSame('draft', $draft->status);
        $this->assertDatabaseHas('mailbox_entries', ['message_id' => $draft->id, 'folder' => 'drafts']);

        $sent = $mail->send($sender, ['to' => $recipient->public_email, 'subject' => 'Final', 'body_html' => '<p>Done</p>'], [], $draft);
        $this->assertSame($draft->id, $sent->id);
        $this->assertSame('sent', $sent->status);
        $this->assertDatabaseHas('mailbox_entries', ['message_id' => $sent->id, 'user_id' => $recipient->id, 'folder' => 'inbox']);
    }

    public function test_attachment_download_is_private(): void
    {
        Storage::fake('local');
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();
        $message = app(MailService::class)->send(
            $sender,
            ['to' => $recipient->public_email, 'subject' => 'File', 'body_html' => '<p>Attached</p>'],
            [UploadedFile::fake()->create('report.pdf', 100, 'application/pdf')],
        );
        $attachment = $message->attachments()->firstOrFail();

        $this->actingAs($recipient)->get('/attachments/'.$attachment->id)->assertOk();
        $this->actingAs($outsider)->get('/attachments/'.$attachment->id)->assertForbidden();
    }

    public function test_trash_is_per_user_and_old_trash_is_purged(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $message = app(MailService::class)->send($sender, ['to' => $recipient->public_email, 'subject' => 'Delete me', 'body_html' => '<p>Body</p>']);
        $entry = MailboxEntry::where('user_id', $recipient->id)->firstOrFail();
        $entry->update(['folder' => 'trash', 'trashed_at' => now()->subDays(31)]);

        $this->artisan('mail:purge-trash')->assertSuccessful();
        $this->assertDatabaseMissing('mailbox_entries', ['id' => $entry->id]);
        $this->assertDatabaseHas('messages', ['id' => $message->id]);
        $this->assertDatabaseHas('mailbox_entries', ['message_id' => $message->id, 'user_id' => $sender->id]);
    }

    public function test_contact_email_is_not_used_as_an_internal_mailbox_address(): void
    {
        Mail::fake();
        $sender = User::factory()->create();
        $recipient = User::factory()->create([
            'email' => 'private.contact@example.net',
            'public_email' => 'recipient@u-mail.local',
        ]);

        $message = app(MailService::class)->send($sender, [
            'to' => $recipient->email,
            'subject' => 'Contact address',
            'body_html' => '<p>External delivery</p>',
        ]);

        $this->assertDatabaseMissing('mailbox_entries', ['message_id' => $message->id, 'user_id' => $recipient->id]);
        $this->assertDatabaseHas('message_recipients', ['message_id' => $message->id, 'user_id' => null, 'email' => $recipient->email]);
        Mail::assertSent(ExternalMessageMail::class, fn (ExternalMessageMail $mail) => $mail->hasTo($recipient->email));
    }
}
