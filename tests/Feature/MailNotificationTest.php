<?php

namespace Tests\Feature;

use App\Models\MailboxEntry;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_manage_notification_preference_and_receives_a_safe_baseline_cursor(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Existing unread mail',
            'body_html' => '<p>Existing body</p>',
        ]);
        $entry = MailboxEntry::where('user_id', $recipient->id)->firstOrFail();

        $this->actingAs($recipient)
            ->get('/settings/notifications')
            ->assertOk()
            ->assertSee('Desktop notifications')
            ->assertSee('open to receive alerts');

        $this->patchJson('/settings/notifications', ['enabled' => true])
            ->assertOk()
            ->assertJson(['enabled' => true, 'cursor' => $entry->id]);
        $this->assertTrue($recipient->fresh()->mail_notifications_enabled);

        $this->patchJson('/settings/notifications', ['enabled' => false])
            ->assertOk()
            ->assertJson(['enabled' => false]);
        $this->assertFalse($recipient->fresh()->mail_notifications_enabled);
    }

    public function test_poll_baselines_existing_mail_then_returns_only_new_unread_inbox_mail(): void
    {
        $sender = User::factory()->create(['name' => 'Notification Sender']);
        $recipient = User::factory()->create(['mail_notifications_enabled' => true]);
        app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Existing mail',
            'body_html' => '<p>Do not notify this old message</p>',
        ]);
        $existing = MailboxEntry::where('user_id', $recipient->id)->firstOrFail();

        $this->actingAs($recipient)->getJson('/poll')
            ->assertOk()
            ->assertJson(['notifications' => [], 'notification_cursor' => $existing->id]);

        $newMessage = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'A new inbox message',
            'body_html' => '<p>Private message body must stay private</p>',
        ]);
        $newEntry = MailboxEntry::where('user_id', $recipient->id)->where('message_id', $newMessage->id)->firstOrFail();

        $response = $this->getJson('/poll?notification_cursor='.$existing->id)
            ->assertOk()
            ->assertJsonPath('notifications.0.id', $newEntry->id)
            ->assertJsonPath('notifications.0.sender', 'Notification Sender')
            ->assertJsonPath('notifications.0.subject', 'A new inbox message')
            ->assertJsonPath('notification_cursor', $newEntry->id);

        $notification = $response->json('notifications.0');
        $this->assertSame(['id', 'sender', 'subject', 'sent_at', 'url'], array_keys($notification));
        $this->assertStringNotContainsString('Private message body', $response->getContent());
        $this->assertStringNotContainsString($recipient->email, $response->getContent());
    }

    public function test_poll_excludes_read_archived_sent_draft_and_other_users_mail(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create(['mail_notifications_enabled' => true]);
        $outsider = User::factory()->create(['mail_notifications_enabled' => true]);

        $read = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Already read',
            'body_html' => '<p>Read</p>',
        ]);
        MailboxEntry::where('user_id', $recipient->id)->where('message_id', $read->id)->update(['is_read' => true]);

        $archived = app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'Already archived',
            'body_html' => '<p>Archive</p>',
        ]);
        MailboxEntry::where('user_id', $recipient->id)->where('message_id', $archived->id)->update(['folder' => 'archive']);

        app(MailService::class)->saveDraft($recipient, ['subject' => 'Draft only', 'body_html' => '<p>Draft</p>']);
        app(MailService::class)->send($recipient, [
            'to' => $sender->public_email,
            'subject' => 'Sent only',
            'body_html' => '<p>Sent</p>',
        ]);
        app(MailService::class)->send($sender, [
            'to' => $outsider->public_email,
            'subject' => 'Private outsider mail',
            'body_html' => '<p>Private</p>',
        ]);

        $this->actingAs($recipient)->getJson('/poll?notification_cursor=0')
            ->assertOk()
            ->assertJson(['notifications' => []])
            ->assertDontSee('Already read')
            ->assertDontSee('Already archived')
            ->assertDontSee('Draft only')
            ->assertDontSee('Sent only')
            ->assertDontSee('Private outsider mail');
    }

    public function test_disabled_notifications_never_return_notification_items(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create(['mail_notifications_enabled' => false]);
        app(MailService::class)->send($sender, [
            'to' => $recipient->public_email,
            'subject' => 'No desktop alert',
            'body_html' => '<p>Mail still reaches the inbox</p>',
        ]);

        $this->actingAs($recipient)->getJson('/poll?notification_cursor=0')
            ->assertOk()
            ->assertJson(['unread' => 1, 'notifications' => []]);
    }
}
