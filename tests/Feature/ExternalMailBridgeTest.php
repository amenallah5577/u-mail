<?php

namespace Tests\Feature;

use App\Jobs\DeliverExternalMessage;
use App\Mail\ExternalMessageMail;
use App\Models\ExternalDelivery;
use App\Models\IncomingImport;
use App\Models\User;
use App\Services\IncomingMailService;
use App\Services\MailService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExternalMailBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_created_account_receives_unique_public_email_and_admin_can_change_it(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->post('/admin/employees', [
            'name' => 'Ahmed Ben Salah',
            'email' => 'ahmed.login@example.test',
        ])->assertSessionHas('status');

        $employee = User::where('email', 'ahmed.login@example.test')->firstOrFail();
        $this->assertSame('ahmed.ben.salah@u-mail.local', $employee->public_email);

        $this->post("/admin/employees/{$employee->id}/public-email", [
            'public_email' => 'a.salah@u-mail.local',
        ])->assertSessionHas('status');
        $this->assertSame('a.salah@u-mail.local', $employee->fresh()->public_email);

        $this->post("/admin/employees/{$employee->id}/public-email", [
            'public_email' => 'wrong@example.net',
        ])->assertSessionHasErrors('public_email');
    }

    public function test_outside_message_goes_directly_to_matching_employee_inbox_without_admin_approval(): void
    {
        config(['owner.email' => 'owner@example.test']);
        $owner = User::factory()->create(['email' => 'owner@example.test', 'role' => 'admin']);
        $employee = User::factory()->create(['public_email' => 'employee@u-mail.local']);
        $other = User::factory()->create();

        $import = app(IncomingMailService::class)->simulate([
            'sender_name' => 'Outside Contact',
            'sender_email' => 'contact@example.net',
            'recipients' => $employee->public_email,
            'subject' => 'Direct outside message',
            'body_text' => 'Hello employee',
        ]);

        $this->assertSame('routed', $import->status);
        $this->assertDatabaseHas('mailbox_entries', ['message_id' => $import->message_id, 'user_id' => $employee->id, 'folder' => 'inbox']);
        $this->assertDatabaseMissing('mailbox_entries', ['message_id' => $import->message_id, 'user_id' => $owner->id]);
        $this->actingAs($employee)->get('/threads/'.$import->message->thread_id)->assertOk()->assertSee('Outside sender');
        $this->actingAs($other)->get('/threads/'.$import->message->thread_id)->assertNotFound();
    }

    public function test_unknown_and_inactive_public_addresses_go_only_to_owner_inbox(): void
    {
        config(['owner.email' => 'owner@example.test']);
        $owner = User::factory()->create(['email' => 'owner@example.test', 'role' => 'admin']);
        $inactive = User::factory()->create(['public_email' => 'inactive@u-mail.local', 'status' => 'inactive']);

        foreach (['missing@u-mail.local', $inactive->public_email] as $address) {
            $import = app(IncomingMailService::class)->simulate([
                'sender_email' => 'contact@example.net',
                'recipients' => $address,
                'subject' => 'Needs owner',
                'body_text' => 'Please route this',
            ]);
            $this->assertSame('owner_intake', $import->status);
            $this->assertDatabaseHas('mailbox_entries', ['message_id' => $import->message_id, 'user_id' => $owner->id]);
            $this->assertDatabaseMissing('mailbox_entries', ['message_id' => $import->message_id, 'user_id' => $inactive->id]);
        }
    }

    public function test_contact_email_does_not_route_incoming_mail_to_employee_inbox(): void
    {
        config(['owner.email' => 'owner@example.test']);
        $owner = User::factory()->create(['email' => 'owner@example.test', 'role' => 'admin']);
        $employee = User::factory()->create([
            'email' => 'private.contact@example.net',
            'public_email' => 'employee@u-mail.local',
        ]);

        $import = app(IncomingMailService::class)->simulate([
            'sender_email' => 'outside@example.net',
            'recipients' => $employee->email,
            'subject' => 'Private contact address',
            'body_text' => 'This must not enter the employee mailbox.',
        ]);

        $this->assertSame('owner_intake', $import->status);
        $this->assertDatabaseHas('mailbox_entries', ['message_id' => $import->message_id, 'user_id' => $owner->id]);
        $this->assertDatabaseMissing('mailbox_entries', ['message_id' => $import->message_id, 'user_id' => $employee->id]);
    }

    public function test_outside_reply_joins_existing_conversation_and_duplicate_import_is_ignored(): void
    {
        Mail::fake();
        $employee = User::factory()->create(['public_email' => 'employee@u-mail.local']);
        $outgoing = app(MailService::class)->send($employee, [
            'to' => 'contact@example.net',
            'subject' => 'Question',
            'body_html' => '<p>Hello</p>',
        ]);

        $data = [
            'internet_message_id' => 'outside-reply@example.net',
            'in_reply_to' => $outgoing->internet_message_id,
            'sender_email' => 'contact@example.net',
            'recipients' => $employee->public_email,
            'subject' => 'Re: Question',
            'body_text' => 'Here is the answer',
        ];
        $first = app(IncomingMailService::class)->simulate($data);
        $second = app(IncomingMailService::class)->simulate($data);

        $this->assertSame($outgoing->thread_id, $first->message->thread_id);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, IncomingImport::where('internet_message_id', 'outside-reply@example.net')->count());
    }

    public function test_eml_import_routes_valid_mail_and_quarantines_dangerous_attachment(): void
    {
        Storage::fake('local');
        config(['owner.email' => 'owner@example.test']);
        User::factory()->create(['email' => 'owner@example.test', 'role' => 'admin']);
        $employee = User::factory()->create(['public_email' => 'employee@u-mail.local']);

        $plain = "From: Outside Contact <contact@example.net>\r\n".
            "To: {$employee->public_email}\r\n".
            "Message-ID: <plain-message@example.net>\r\n".
            "Subject: Imported message\r\n".
            "Content-Type: text/plain; charset=UTF-8\r\n\r\n".
            'Hello from an email file';
        $routed = app(IncomingMailService::class)->importEml($plain);
        $this->assertSame('routed', $routed->status);
        $this->assertDatabaseHas('mailbox_entries', ['message_id' => $routed->message_id, 'user_id' => $employee->id]);

        $unsafe = "From: Contact <contact@example.net>\r\n".
            "To: {$employee->public_email}\r\n".
            "Message-ID: <unsafe-message@example.net>\r\n".
            "Subject: Unsafe file\r\n".
            "MIME-Version: 1.0\r\n".
            "Content-Type: multipart/mixed; boundary=boundary\r\n\r\n".
            "--boundary\r\nContent-Type: text/plain\r\n\r\nHello\r\n".
            "--boundary\r\nContent-Type: application/octet-stream; name=\"run.exe\"\r\n".
            "Content-Disposition: attachment; filename=\"run.exe\"\r\n".
            "Content-Transfer-Encoding: base64\r\n\r\n".base64_encode('unsafe')."\r\n--boundary--\r\n";
        $quarantined = app(IncomingMailService::class)->importEml($unsafe);
        $this->assertSame('quarantined', $quarantined->status);
        $this->assertNull($quarantined->message_id);
        Storage::disk('local')->assertExists($quarantined->raw_path);
    }

    public function test_external_delivery_uses_employee_public_address_and_shows_user_friendly_status(): void
    {
        Mail::fake();
        $employee = User::factory()->create(['name' => 'Employee Sender', 'public_email' => 'employee@u-mail.local']);
        $message = app(MailService::class)->send($employee, [
            'to' => 'outside@example.net',
            'subject' => 'Outside delivery',
            'body_html' => '<p>Hello outside</p>',
        ]);

        $delivery = ExternalDelivery::where('message_id', $message->id)->firstOrFail();
        $this->assertSame('delivered', $delivery->status);
        $job = new DeliverExternalMessage($delivery->id);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        Mail::assertSent(ExternalMessageMail::class, fn (ExternalMessageMail $mail) => $mail->hasFrom('employee@u-mail.local'));

        $this->actingAs($employee)->get('/threads/'.$message->thread_id)
            ->assertOk()
            ->assertSee('Delivered outside U-Mail')
            ->assertDontSee('SMTP')
            ->assertDontSee('queue');
    }

    public function test_outside_mail_tools_are_not_exposed_in_the_owner_or_admin_interface(): void
    {
        config(['owner.email' => 'owner@example.test']);
        $owner = User::factory()->create(['email' => 'owner@example.test', 'role' => 'admin']);
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/owner/incoming')->assertNotFound();
        $this->actingAs($owner)->get('/owner/incoming')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('Outside mail');
    }
}
