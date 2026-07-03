<?php

namespace Tests\Feature;

use App\Models\MailboxEntry;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MailboxBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_compose_interface_hides_technical_delivery_text_and_renders_polished_controls(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/')
            ->assertOk()
            ->assertDontSee('External addresses are delivered through the configured SMTP service.')
            ->assertSee('New message')
            ->assertSee('Cc · Bcc')
            ->assertSee('Formatting')
            ->assertSee('Attach');
    }

    public function test_search_polling_read_star_archive_trash_and_restore_work_per_user(): void
    {
        $sender = User::factory()->create(['name' => 'Ahmed Sender']);
        $recipient = User::factory()->create();
        $message = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Specific searchable subject',
            'body_html' => '<p>Confidential planning note</p>',
        ]);
        $entry = MailboxEntry::where('user_id', $recipient->id)->firstOrFail();

        $this->actingAs($recipient)->get('/?q=searchable')->assertOk()->assertSee('Specific searchable subject');
        $this->get('/poll')->assertJson(['unread' => 1]);
        $this->get('/threads/'.$message->thread_id)->assertOk();
        $this->assertDatabaseHas('mailbox_entries', ['id' => $entry->id, 'is_read' => true]);

        $this->post('/mailbox/'.$entry->id, ['action' => 'star']);
        $this->assertDatabaseHas('mailbox_entries', ['id' => $entry->id, 'is_starred' => true]);
        $this->post('/mailbox/'.$entry->id, ['action' => 'archive']);
        $this->assertDatabaseHas('mailbox_entries', ['id' => $entry->id, 'folder' => 'archive']);
        $this->post('/mailbox/'.$entry->id, ['action' => 'trash']);
        $this->assertDatabaseHas('mailbox_entries', ['id' => $entry->id, 'folder' => 'trash']);
        $this->post('/mailbox/'.$entry->id, ['action' => 'restore']);
        $this->assertDatabaseHas('mailbox_entries', ['id' => $entry->id, 'folder' => 'inbox']);
    }

    public function test_advanced_mail_filters_combine_and_preserve_context(): void
    {
        Storage::fake('local');
        $sender = User::factory()->create(['name' => 'Ahmed Sender', 'public_email' => 'ahmed.sender@u-mail.local']);
        $recipient = User::factory()->create(['name' => 'Budget Receiver', 'public_email' => 'budget.receiver@u-mail.local']);
        $otherSender = User::factory()->create(['name' => 'Other Sender']);

        $matching = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Budget Report Alpha',
            'body_html' => '<p>'.str_repeat('alpha planning ', 120).'</p>',
        ], [UploadedFile::fake()->create('budget.pdf', 32, 'application/pdf')]);
        $matchingEntry = MailboxEntry::where('user_id', $recipient->id)->where('message_id', $matching->id)->firstOrFail();
        $matchingEntry->update(['is_starred' => true]);

        app(MailService::class)->send($otherSender, [
            'to' => $recipient->public_email,
            'subject' => 'Budget Report Wrong',
            'body_html' => '<p>wrong alpha planning</p>',
        ]);

        $query = http_build_query([
            'q' => 'alpha',
            'from' => 'Ahmed',
            'to' => 'Budget Receiver',
            'subject' => 'Budget Report',
            'exact' => 'alpha planning',
            'exclude' => 'wrong',
            'read_status' => 'unread',
            'starred' => 'starred',
            'attachments' => 'yes',
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
            'size_operator' => 'larger',
            'size_value' => 1,
            'size_unit' => 'kb',
        ]);

        $this->actingAs($recipient)->get('/mail/inbox?'.$query)
            ->assertOk()
            ->assertSee('Budget Report Alpha')
            ->assertDontSee('Budget Report Wrong')
            ->assertSee('From: Ahmed')
            ->assertSee('subject=Budget%20Report', false)
            ->assertSee('size_value=1', false);
    }

    public function test_recipient_filters_do_not_expose_hidden_bcc_recipients_to_other_participants(): void
    {
        $sender = User::factory()->create();
        $visibleRecipient = User::factory()->create();
        $hiddenRecipient = User::factory()->create(['name' => 'Hidden Bcc Person', 'public_email' => 'hidden.bcc@u-mail.local']);

        app(MailService::class)->send($sender, [
            'to' => $visibleRecipient->public_email,
            'bcc' => $hiddenRecipient->public_email,
            'subject' => 'Private BCC Search',
            'body_html' => '<p>Visible recipient should not discover hidden BCC.</p>',
        ]);

        $this->actingAs($visibleRecipient)->get('/mail/inbox?to=hidden.bcc')
            ->assertOk()
            ->assertDontSee('Private BCC Search');

        $this->actingAs($hiddenRecipient)->get('/mail/inbox?to=hidden.bcc')
            ->assertOk()
            ->assertSee('Private BCC Search');
    }

    public function test_admin_without_a_mailbox_copy_cannot_read_private_thread(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $message = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Private',
            'body_html' => '<p>Private body</p>',
        ]);

        $this->actingAs($admin)->get('/threads/'.$message->thread_id)->assertNotFound();
    }

    public function test_owner_can_discard_a_draft_but_another_user_cannot(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $draft = app(MailService::class)->saveDraft($owner, ['subject' => 'Temporary', 'body_html' => '<p>Draft</p>']);

        $this->actingAs($other)->delete('/messages/'.$draft->id.'/draft')->assertForbidden();
        $this->actingAs($owner)->delete('/messages/'.$draft->id.'/draft')->assertNoContent();
        $this->assertDatabaseMissing('messages', ['id' => $draft->id]);
    }
}
