<?php

namespace Tests\Feature;

use App\Models\AccountToken;
use App\Models\User;
use App\Notifications\AccountApprovedNotification;
use App\Notifications\RegistrationEmailCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PublicRegistrationApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_can_be_disabled_until_real_contact_email_delivery_is_ready(): void
    {
        config(['registration.enabled' => false]);

        $this->get('/login')
            ->assertOk()
            ->assertSee('Account requests temporarily unavailable')
            ->assertDontSee('Request an account');

        $this->get('/register')
            ->assertRedirect('/login')
            ->assertSessionHas('status');

        $this->post('/register', [
            'first_name' => 'Pilot',
            'last_name' => 'Coworker',
            'email' => 'pilot.coworker@example.net',
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('users', ['email' => 'pilot.coworker@example.net']);
    }

    public function test_requester_receives_code_and_only_enters_admin_queue_after_confirming_contact_email(): void
    {
        Notification::fake();
        $this->get('/register')->assertOk()->assertSee('Create your request');

        $this->post('/register', [
            'first_name' => 'Ahmed',
            'last_name' => 'Ben Salah',
            'email' => 'ahmed.contact@example.net',
            'phone' => '',
        ])->assertRedirect('/register/verify');

        $requester = User::where('email', 'ahmed.contact@example.net')->firstOrFail();
        $this->assertSame('email_verification', $requester->status);
        $this->assertSame('ahmed.ben.salah@u-mail.local', $requester->public_email);
        $this->assertNull($requester->password);
        $this->assertNull($requester->registration_requested_at);
        Notification::assertSentTo($requester, RegistrationEmailCodeNotification::class);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/employees')->assertDontSee($requester->name);

        $code = null;
        Notification::assertSentTo($requester, RegistrationEmailCodeNotification::class, function (RegistrationEmailCodeNotification $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });
        $this->post('/logout');
        $this->post('/register/verify', ['email' => $requester->email, 'code' => $code])
            ->assertRedirect('/login');

        $requester->refresh();
        $this->assertSame('requested', $requester->status);
        $this->assertNotNull($requester->email_verified_at);
        $this->assertNotNull($requester->registration_requested_at);
    }

    public function test_contact_email_is_required_and_phone_is_optional(): void
    {
        Notification::fake();
        $this->post('/register', [
            'first_name' => 'Phone',
            'last_name' => 'Requester',
            'email' => '',
            'phone' => '+216 71 000 000',
        ])->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['phone' => '+216 71 000 000']);

        $this->post('/register', [
            'first_name' => 'Email',
            'last_name' => 'Only',
            'email' => 'email.only@example.net',
            'phone' => '',
        ])->assertRedirect('/register/verify');
        $this->assertDatabaseHas('users', ['email' => 'email.only@example.net', 'phone' => null]);
    }

    public function test_admin_approval_generates_password_emails_credentials_and_allows_public_address_login(): void
    {
        Notification::fake();
        $requester = $this->requestAndVerify('Approved', 'Employee', 'approved.contact@example.net');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/admin/employees/{$requester->id}/approve")
            ->assertSessionHas('status');

        $requester->refresh();
        $this->assertSame('active', $requester->status);
        $this->assertSame($admin->id, $requester->approved_by);
        $this->assertNotNull($requester->approved_at);
        $temporaryPassword = null;
        Notification::assertSentTo($requester, AccountApprovedNotification::class, function (AccountApprovedNotification $notification) use (&$temporaryPassword) {
            $temporaryPassword = $notification->temporaryPassword;

            return true;
        });
        $this->assertTrue(Hash::check($temporaryPassword, $requester->password));
        $this->assertSame($temporaryPassword, $requester->credential->password_encrypted);

        $this->post('/logout');
        $this->post('/login', ['email' => $requester->public_email, 'password' => $temporaryPassword])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($requester);
    }

    public function test_admin_can_reject_request_and_rejected_account_cannot_login(): void
    {
        Notification::fake();
        $requester = $this->requestAndVerify('Rejected', 'Requester', 'rejected@example.net');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/admin/employees/{$requester->id}/reject")
            ->assertSessionHas('status');
        $this->assertSame('rejected', $requester->fresh()->status);
        $this->assertNotNull($requester->fresh()->rejected_at);

        $this->post('/logout');
        $this->post('/login', ['email' => $requester->public_email, 'password' => 'anything'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_admin_dashboard_separates_requests_from_approved_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $requester = User::factory()->create(['status' => 'requested', 'password' => null, 'registration_requested_at' => now(), 'email_verified_at' => now()]);
        $unverified = User::factory()->create(['status' => 'email_verification', 'password' => null, 'registration_requested_at' => null, 'email_verified_at' => null]);
        $active = User::factory()->create(['status' => 'active']);

        $this->actingAs($admin)->get('/admin/employees')
            ->assertOk()
            ->assertSee('Approve new accounts')
            ->assertSee($requester->name)
            ->assertSee('Approve and send details')
            ->assertSee($active->name)
            ->assertDontSee($unverified->name);
    }

    public function test_admin_cannot_approve_an_unverified_contact_email(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $requester = User::factory()->create([
            'status' => 'requested',
            'password' => null,
            'registration_requested_at' => now(),
            'email_verified_at' => null,
        ]);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/admin/employees/{$requester->id}/approve")
            ->assertStatus(422);
        $this->assertSame('requested', $requester->fresh()->status);
    }

    public function test_confirmation_code_expires_after_fifteen_minutes_and_can_be_resent(): void
    {
        Notification::fake();
        $this->post('/register', [
            'first_name' => 'Code',
            'last_name' => 'Check',
            'email' => 'code.check@example.net',
            'phone' => '',
        ]);
        $requester = User::where('email', 'code.check@example.net')->firstOrFail();
        $firstToken = AccountToken::where('user_id', $requester->id)->latest('id')->firstOrFail();
        $this->assertTrue($firstToken->expires_at->between(now()->addMinutes(14), now()->addMinutes(16)));

        $this->post('/register/verify/resend', ['email' => $requester->email])->assertSessionHas('status');
        $this->assertNotNull($firstToken->fresh()->used_at);
        $this->assertSame(2, AccountToken::where('user_id', $requester->id)->count());
        Notification::assertSentToTimes($requester, RegistrationEmailCodeNotification::class, 2);
    }

    public function test_email_actions_open_the_confirmation_page_and_employee_mailbox(): void
    {
        $requester = User::factory()->create([
            'email' => 'confirm.link@example.net',
            'status' => 'email_verification',
            'email_verified_at' => null,
            'password' => null,
        ]);
        $confirmationMail = (new RegistrationEmailCodeNotification('123456'))->toMail($requester);
        $approvedMail = (new AccountApprovedNotification('TemporaryPass123!'))->toMail($requester);

        $this->assertSame(route('register.verify', ['email' => $requester->email]), $confirmationMail->actionUrl);
        $this->assertSame(route('mailbox'), $approvedMail->actionUrl);

        $loggedInEmployee = User::factory()->create();
        $this->actingAs($loggedInEmployee)
            ->get(route('register.verify', ['email' => $requester->email]))
            ->assertOk()
            ->assertSee($requester->email);
        $this->get($approvedMail->actionUrl)->assertOk()->assertSee('Inbox');

        $this->post('/logout');
        $this->get($approvedMail->actionUrl)->assertRedirect('/login');
    }

    private function requestAndVerify(string $firstName, string $lastName, string $email): User
    {
        $this->post('/register', [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => '',
        ]);
        $requester = User::where('email', $email)->firstOrFail();
        $code = null;
        Notification::assertSentTo($requester, RegistrationEmailCodeNotification::class, function (RegistrationEmailCodeNotification $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });
        $this->post('/register/verify', ['email' => $email, 'code' => $code])->assertRedirect('/login');

        return $requester->fresh();
    }
}
