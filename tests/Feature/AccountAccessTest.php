<?php

namespace Tests\Feature;

use App\Models\AccountToken;
use App\Models\User;
use App\Notifications\AccountCodeNotification;
use App\Services\AccountCredentialService;
use App\Services\AccountTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_employee_and_employee_activates_with_single_use_code(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->post('/admin/employees', [
            'name' => 'New Employee',
            'email' => 'new@utica.test',
            'role' => 'admin',
        ]);
        $response->assertSessionHas('status')->assertSessionMissing('activation_code');
        $user = User::where('email', 'new@utica.test')->firstOrFail();
        $this->assertSame('employee', $user->role);

        $code = null;
        Notification::assertSentTo($user, AccountCodeNotification::class, function (AccountCodeNotification $notification) use (&$code) {
            $code = $notification->code;

            return $notification->purpose === 'activation';
        });

        $this->post('/logout');
        $this->post('/activate', [
            'email' => 'new@utica.test',
            'code' => $code,
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ])->assertRedirect('/login');

        $user->refresh();
        $this->assertTrue($user->isActive());
        $this->assertTrue(Hash::check('StrongPass123!', $user->password));
        $this->assertSame('StrongPass123!', $user->credential->password_encrypted);
        $this->assertNotNull(AccountToken::first()->used_at);

        $this->post('/activate', [
            'email' => 'new@utica.test',
            'code' => $code,
            'password' => 'AnotherPass123!',
            'password_confirmation' => 'AnotherPass123!',
        ])->assertSessionHasErrors('email');
    }

    public function test_active_user_requests_and_uses_a_private_password_reset_code(): void
    {
        Notification::fake();
        $employee = User::factory()->create([
            'email' => 'private.reset@example.net',
            'public_email' => 'reset.employee@u-mail.local',
        ]);
        DB::table('sessions')->insert([
            'id' => 'employee-session',
            'user_id' => $employee->id,
            'payload' => 'encrypted-session-payload',
            'last_activity' => time(),
        ]);

        $this->post('/reset-password/request', ['email' => $employee->email])
            ->assertSessionHas('status');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('account_tokens', 0);

        $this->post('/reset-password/request', ['email' => $employee->public_email])
            ->assertSessionHas('status');

        $code = null;
        Notification::assertSentTo($employee, AccountCodeNotification::class, function (AccountCodeNotification $notification) use (&$code) {
            $code = $notification->code;

            return $notification->purpose === 'reset';
        });

        $this->post('/reset-password', [
            'email' => $employee->email,
            'code' => $code,
            'password' => 'NewStrongPass123!',
            'password_confirmation' => 'NewStrongPass123!',
        ])->assertSessionHasErrors('code');

        $this->post('/reset-password', [
            'email' => $employee->public_email,
            'code' => $code,
            'password' => 'NewStrongPass123!',
            'password_confirmation' => 'NewStrongPass123!',
        ])->assertRedirect('/login');

        $this->assertTrue(Hash::check('NewStrongPass123!', $employee->fresh()->password));
        $this->assertSame('NewStrongPass123!', $employee->credential->password_encrypted);
        $this->assertDatabaseMissing('sessions', ['id' => 'employee-session']);
        $this->assertSame($employee->email, $employee->routeNotificationFor('mail'));
    }

    public function test_password_reset_page_uses_u_mail_only_and_has_no_admin_sign_in_link(): void
    {
        $this->get('/reset-password')
            ->assertOk()
            ->assertSee('U-Mail address')
            ->assertSee('U-Mail password reset')
            ->assertDontSee('U-Mail or contact email')
            ->assertDontSee('Enter either address')
            ->assertDontSee('Employee and administrator reset')
            ->assertDontSee('Admin sign in');
    }

    public function test_admin_can_manage_employees_and_promote_an_active_employee(): void
    {
        $adminLoginPath = '/'.trim(config('security.admin_login_path'), '/');
        $admin = User::factory()->create(['role' => 'admin']);
        $activeEmployee = User::factory()->create();
        $inactiveEmployee = User::factory()->create(['status' => 'inactive']);
        $deletedEmployee = User::factory()->create();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->post("/admin/employees/{$inactiveEmployee->id}/status", ['status' => 'active'])
            ->assertSessionHas('status');
        $this->assertSame('active', $inactiveEmployee->fresh()->status);

        $this->post("/admin/employees/{$activeEmployee->id}/promote")
            ->assertSessionHas('status');
        $this->assertSame('admin', $activeEmployee->fresh()->role);

        $this->post('/logout')->assertRedirect($adminLoginPath);
        $this->post('/login', ['email' => $activeEmployee->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post($adminLoginPath, ['email' => $activeEmployee->email, 'password' => 'password'])
            ->assertRedirect('/admin/employees');
        $this->assertAuthenticatedAs($activeEmployee);
        $this->post('/logout')->assertRedirect($adminLoginPath);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);

        $this->delete("/admin/employees/{$deletedEmployee->id}")
            ->assertSessionHas('status');
        $this->assertSoftDeleted($deletedEmployee);
    }

    public function test_admin_cannot_deactivate_or_delete_an_administrator_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post("/admin/employees/{$otherAdmin->id}/status", ['status' => 'inactive'])
            ->assertStatus(422);

        $this->delete("/admin/employees/{$otherAdmin->id}")
            ->assertStatus(422);
    }

    public function test_only_the_configured_owner_can_view_the_credentials_page(): void
    {
        config(['owner.email' => 'owner@utica.test']);
        $owner = User::factory()->create(['name' => 'Owner Account', 'email' => 'owner@utica.test', 'role' => 'admin']);
        $admin = User::factory()->create(['name' => 'Other Admin', 'role' => 'admin']);
        $employee = User::factory()->create(['name' => 'Active Employee']);
        $pending = User::factory()->create(['name' => 'Pending Employee', 'status' => 'pending', 'password' => null]);
        $deleted = User::factory()->create(['name' => 'Deleted Employee']);
        $deleted->delete();
        app(AccountCredentialService::class)->store($owner, 'OwnerPass123');
        app(AccountCredentialService::class)->store($employee, 'EmployeePass123');
        app(AccountCredentialService::class)->store($deleted, 'DeletedPass123');

        $this->actingAs($owner)
            ->get('/owner/credentials')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertHeader('Vary', 'Cookie')
            ->assertSee($deleted->name)
            ->assertSee($employee->name)
            ->assertSee($owner->name)
            ->assertSee($pending->name)
            ->assertDontSee('DeletedPass123')
            ->assertDontSee('EmployeePass123')
            ->assertDontSee('OwnerPass123');

        $this->post('/owner/credentials/'.$owner->id.'/reveal')
            ->assertRedirect('/confirm-password');
        $this->assertDatabaseMissing('security_events', ['event' => 'owner.credential_revealed']);

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post('/owner/credentials/'.$owner->id.'/reveal')
            ->assertRedirect('/owner/credentials');
        $this->assertDatabaseHas('security_events', [
            'event' => 'owner.credential_revealed',
            'actor_id' => $owner->id,
            'target_user_id' => $owner->id,
        ]);
        $this->get('/owner/credentials')
            ->assertSee('OwnerPass123')
            ->assertDontSee('EmployeePass123');

        $this->get('/admin/employees')
            ->assertOk()
            ->assertDontSee('OwnerPass123')
            ->assertDontSee('EmployeePass123');

        $this->actingAs($admin)
            ->get('/owner/credentials')
            ->assertForbidden();

        $this->actingAs($employee)
            ->get('/owner/credentials')
            ->assertForbidden();

        $storedPassword = DB::table('account_credentials')
            ->where('user_id', $owner->id)
            ->value('password_encrypted');
        $this->assertNotSame('OwnerPass123', $storedPassword);
    }

    public function test_owner_can_search_and_filter_the_credential_registry(): void
    {
        config(['owner.email' => 'owner@utica.test']);
        $owner = User::factory()->create(['email' => 'owner@utica.test', 'role' => 'admin']);
        $deleted = User::factory()->create([
            'name' => 'Archived Credential',
            'email' => 'archived.contact@example.test',
            'public_email' => 'archived.credential@u-mail.local',
        ]);
        $other = User::factory()->create([
            'name' => 'Visible Elsewhere',
            'email' => 'other.contact@example.test',
            'public_email' => 'other.account@u-mail.local',
        ]);
        $deleted->delete();

        $this->actingAs($owner)
            ->get('/owner/credentials?q=archived&role=employee&status=deleted')
            ->assertOk()
            ->assertSee('Find credentials')
            ->assertSeeText('1 matching account')
            ->assertSee($deleted->public_email)
            ->assertDontSee($deleted->email)
            ->assertDontSee($other->email)
            ->assertDontSee($other->public_email);

        $this->get('/owner/credentials?q=archived.contact%40example.test')
            ->assertOk()
            ->assertSeeText('0 matching accounts')
            ->assertDontSee($deleted->public_email);
    }

    public function test_admin_can_search_and_filter_managed_accounts_without_hiding_requests(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create([
            'name' => 'Directory Target',
            'email' => 'directory.target@example.test',
            'public_email' => 'directory.target@u-mail.local',
            'status' => 'inactive',
        ]);
        $other = User::factory()->create([
            'name' => 'Different Employee',
            'email' => 'different.employee@example.test',
            'public_email' => 'different.employee@u-mail.local',
            'status' => 'active',
        ]);
        $requester = User::factory()->create([
            'name' => 'Waiting Request',
            'email' => 'waiting.request@example.test',
            'status' => 'requested',
            'password' => null,
            'registration_requested_at' => now(),
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/employees?q=directory&role=employee&status=inactive')
            ->assertOk()
            ->assertSee('Find an account')
            ->assertSeeText('1 matching account')
            ->assertSee($target->public_email)
            ->assertDontSee($target->email)
            ->assertDontSee($other->email)
            ->assertDontSee($requester->email)
            ->assertSee('Verified contact email hidden')
            ->assertDontSee($other->public_email)
            ->assertSee($requester->mailAddress());

        $this->get('/admin/employees?q=directory.target%40example.test')
            ->assertOk()
            ->assertSeeText('0 matching accounts')
            ->assertDontSee($target->public_email);
    }

    public function test_create_admin_command_synchronizes_the_owner_credential_registry(): void
    {
        $this->artisan('utica:create-admin', [
            'email' => 'new-admin@utica.test',
            '--name' => 'New Administrator',
            '--password' => 'CommandPass123!',
        ])->assertSuccessful();

        $admin = User::where('email', 'new-admin@utica.test')->firstOrFail();

        $this->assertTrue($admin->isAdmin());
        $this->assertSame('CommandPass123!', $admin->credential->password_encrypted);
        $this->assertNotSame(
            'CommandPass123!',
            DB::table('account_credentials')->where('user_id', $admin->id)->value('password_encrypted'),
        );
    }

    public function test_inactive_user_cannot_login_and_employee_cannot_access_admin(): void
    {
        $adminLoginPath = '/'.trim(config('security.admin_login_path'), '/');
        $inactive = User::factory()->create(['status' => 'inactive']);
        $this->post('/login', ['email' => $inactive->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->get('/admin/employees')->assertRedirect($adminLoginPath);
        $this->get('/owner/credentials')->assertRedirect($adminLoginPath);

        $employee = User::factory()->create();
        $this->actingAs($employee)
            ->get('/admin/employees')
            ->assertForbidden();

        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'status' => 'inactive']);
        $this->actingAs($inactiveAdmin)
            ->get('/')
            ->assertRedirect($adminLoginPath);
        $this->assertGuest();
    }

    public function test_admin_login_endpoint_is_hidden_and_public_login_rejects_admins_generically(): void
    {
        $adminLoginPath = '/'.trim(config('security.admin_login_path'), '/');
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['email' => 'owner@utica.test', 'role' => 'admin']);
        config(['owner.email' => 'owner@utica.test']);

        $this->get('/admin/login')->assertNotFound();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password'])->assertNotFound();

        $this->get($adminLoginPath)
            ->assertOk()
            ->assertSee('RESTRICTED SIGN IN')
            ->assertDontSee('Keep me signed in');

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'The credentials are invalid or the account is inactive.']);
        $this->assertGuest();

        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'The credentials are invalid or the account is inactive.']);
        $this->assertGuest();

        $this->post($adminLoginPath, ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/admin/employees');
        $this->assertAuthenticatedAs($admin);
        $this->get('/login')->assertRedirect('/admin/employees');

        $this->post('/logout')->assertRedirect($adminLoginPath);
        $this->post($adminLoginPath, ['email' => $owner->email, 'password' => 'password'])
            ->assertRedirect('/owner/credentials');
        $this->assertAuthenticatedAs($owner);
        $this->get('/login')->assertRedirect('/owner/credentials');
    }

    public function test_login_page_has_no_admin_route_reference(): void
    {
        $adminLoginPath = '/'.trim(config('security.admin_login_path'), '/');

        $this->get('/login')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertHeader('Vary', 'Cookie')
            ->assertSee('Employee access only')
            ->assertSee('EMPLOYEE SIGN IN')
            ->assertDontSee('/admin/login')
            ->assertDontSee($adminLoginPath)
            ->assertDontSee('Admin portal')
            ->assertDontSee('RESTRICTED SIGN IN');

        foreach (['employee-login', 'register', 'register-verify', 'activate', 'reset-password'] as $view) {
            $source = file_get_contents(resource_path("views/auth/{$view}.blade.php"));
            $this->assertStringNotContainsString('/admin/login', $source);
            $this->assertStringNotContainsString($adminLoginPath, $source);
        }
    }

    public function test_remember_me_is_allowed_for_employees_and_ignored_for_admins_and_owner(): void
    {
        config(['owner.email' => 'owner@utica.test']);
        $adminLoginPath = '/'.trim(config('security.admin_login_path'), '/');
        $employee = User::factory()->create(['remember_token' => null]);
        $admin = User::factory()->create(['role' => 'admin', 'remember_token' => null]);
        $owner = User::factory()->create(['email' => 'owner@utica.test', 'role' => 'admin', 'remember_token' => null]);

        $this->post('/login', ['email' => $employee->email, 'password' => 'password', 'remember' => 'on'])
            ->assertRedirect('/');
        $this->assertNotNull($employee->fresh()->remember_token);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
        $this->post('/login', ['email' => $employee->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post($adminLoginPath, ['email' => $admin->email, 'password' => 'password', 'remember' => 'on'])
            ->assertRedirect('/admin/employees');
        $this->assertNull($admin->fresh()->remember_token);
        $this->post('/logout');

        $this->post($adminLoginPath, ['email' => $owner->email, 'password' => 'password', 'remember' => 'on'])
            ->assertRedirect('/owner/credentials');
        $this->assertNull($owner->fresh()->remember_token);
        $this->post('/logout');
        $this->assertGuest();
    }

    public function test_login_posts_do_not_reuse_an_existing_authenticated_session(): void
    {
        config(['owner.email' => 'owner@utica.test']);
        $adminLoginPath = '/'.trim(config('security.admin_login_path'), '/');
        $employee = User::factory()->create(['role' => 'employee']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->post('/login', ['email' => $employee->email, 'password' => 'password'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($employee);

        $this->post('/login', ['email' => $employee->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');

        $this->post($adminLoginPath, ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/admin/employees');
        $this->assertAuthenticatedAs($admin);

        $this->post($adminLoginPath, ['email' => $admin->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->get('/admin/employees')->assertRedirect($adminLoginPath);
    }

    public function test_session_status_supports_history_logout_checks(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);

        $this->getJson('/auth/session')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertHeader('Vary', 'Cookie')
            ->assertJson(['authenticated' => false, 'user_id' => null]);

        $this->actingAs($employee)->getJson('/auth/session')
            ->assertOk()
            ->assertJson(['authenticated' => true, 'user_id' => $employee->id]);

        $this->post('/logout')
            ->assertRedirect('/login')
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertHeader('Vary', 'Cookie')
            ->assertHeader('Clear-Site-Data', '"cache", "storage"')
            ->assertCookie('u_mail_signed_out', '1');
        $this->assertGuest();
        $this->getJson('/auth/session')
            ->assertOk()
            ->assertJson(['authenticated' => false, 'user_id' => null]);
    }

    public function test_json_logout_uses_fast_no_content_response_for_history_replacement(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);

        $this->actingAs($employee)->postJson('/logout')
            ->assertNoContent()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertHeader('Vary', 'Cookie')
            ->assertHeaderMissing('Clear-Site-Data')
            ->assertCookie('u_mail_signed_out', '1');

        $this->assertGuest();
    }

    public function test_logout_script_replaces_current_history_entry_without_new_window(): void
    {
        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("document.querySelectorAll('[data-logout-form]')", $source);
        $this->assertStringContainsString('fetch(form.action', $source);
        $this->assertStringContainsString('new FormData(form)', $source);
        $this->assertStringContainsString("Accept: 'application/json'", $source);
        $this->assertStringContainsString("redirect: 'manual'", $source);
        $this->assertStringContainsString('window.location.replace(loginUrl)', $source);
        $this->assertStringContainsString('HTMLFormElement.prototype.submit.call(form)', $source);
        $this->assertStringNotContainsString('window.open(', $source);
        $this->assertStringNotContainsString("target = '_blank'", $source);
        $this->assertStringNotContainsString('target = "_blank"', $source);
    }

    public function test_each_role_logs_in_to_its_own_workspace(): void
    {
        config(['owner.email' => 'owner@utica.test']);
        $adminLoginPath = '/'.trim(config('security.admin_login_path'), '/');
        $employee = User::factory()->create(['role' => 'employee']);
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['email' => 'owner@utica.test', 'role' => 'admin']);

        $this->post('/login', ['email' => $employee->email, 'password' => 'password'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($employee);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post($adminLoginPath, ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/admin/employees');
        $this->assertAuthenticatedAs($admin);

        $this->post('/logout')->assertRedirect($adminLoginPath);
        $this->assertGuest();

        $this->post($adminLoginPath, ['email' => $owner->email, 'password' => 'password'])
            ->assertRedirect('/owner/credentials');
        $this->assertAuthenticatedAs($owner);

        $this->post('/logout')->assertRedirect($adminLoginPath);
        $this->assertGuest();
    }

    public function test_admin_activation_and_reset_return_to_hidden_admin_login(): void
    {
        $adminLoginPath = '/'.trim(config('security.admin_login_path'), '/');
        $pendingAdmin = User::factory()->create(['role' => 'admin', 'status' => 'pending', 'password' => null, 'activated_at' => null]);
        $activationCode = app(AccountTokenService::class)->issue($pendingAdmin, 'activation');

        $this->post('/activate', [
            'email' => $pendingAdmin->email,
            'code' => $activationCode,
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ])->assertRedirect($adminLoginPath);

        $activeAdmin = User::factory()->create(['role' => 'admin', 'public_email' => 'reset.admin@u-mail.local']);
        $resetCode = app(AccountTokenService::class)->issue($activeAdmin, 'reset');

        $this->post('/reset-password', [
            'email' => $activeAdmin->public_email,
            'code' => $resetCode,
            'password' => 'NewStrongPass123!',
            'password_confirmation' => 'NewStrongPass123!',
        ])->assertRedirect($adminLoginPath);
    }

    public function test_employee_can_sign_in_with_u_mail_or_private_contact_email(): void
    {
        $employee = User::factory()->create([
            'email' => 'private.contact@example.net',
            'public_email' => 'employee@u-mail.local',
        ]);

        $this->post('/login', ['email' => $employee->public_email, 'password' => 'password'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($employee);

        $this->post('/logout');
        $this->post('/login', ['email' => $employee->email, 'password' => 'password'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($employee);
    }

    public function test_account_notifications_use_private_contact_email_not_u_mail_address(): void
    {
        $employee = User::factory()->create([
            'email' => 'private.notifications@example.net',
            'public_email' => 'notifications@u-mail.local',
        ]);

        $this->assertSame($employee->email, $employee->routeNotificationFor('mail'));
        $this->assertNotSame($employee->public_email, $employee->routeNotificationFor('mail'));
    }

    public function test_employee_mailbox_does_not_render_administration_navigation(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);

        $this->actingAs($employee)
            ->get('/')
            ->assertOk()
            ->assertDontSee('Administration')
            ->assertDontSee('Employees');
    }

    public function test_expired_activation_code_is_rejected(): void
    {
        $user = User::factory()->create(['status' => 'pending', 'password' => null, 'activated_at' => null]);
        $code = app(AccountTokenService::class)->issue($user, 'activation');
        AccountToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->post('/activate', [
            'email' => $user->email,
            'code' => $code,
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ])->assertSessionHasErrors('code');
    }
}
