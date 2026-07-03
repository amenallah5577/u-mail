<?php

namespace Tests\Feature;

use App\Models\AccountToken;
use App\Models\MfaChallenge;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Notifications\MfaCodeNotification;
use App\Services\AccountTokenService;
use App\Services\MfaService;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SecurityUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_without_mfa_logs_in_normally_and_employee_login_hides_admin_link(): void
    {
        $employee = User::factory()->create();

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('Admin portal')
            ->assertDontSee('/admin/login')
            ->assertDontSee('/'.trim(config('security.admin_login_path'), '/'))
            ->assertSee('EMPLOYEE SIGN IN');
        $this->post('/login', ['email' => $employee->email, 'password' => 'password'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($employee);
    }

    public function test_email_mfa_challenge_completes_login(): void
    {
        Notification::fake();
        $employee = User::factory()->create();
        app(MfaService::class)->enableEmail($employee);

        $this->post('/login', ['email' => $employee->email, 'password' => 'password'])
            ->assertRedirect('/mfa/challenge');
        $this->assertGuest();

        $this->post('/mfa/challenge/method', ['method' => 'email'])->assertSessionHas('status');
        $code = null;
        Notification::assertSentTo($employee, MfaCodeNotification::class, function (MfaCodeNotification $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        $this->post('/mfa/challenge/verify', ['code' => $code])
            ->assertRedirect('/')
            ->assertCookieExpired('u_mail_signed_out');
        $this->assertAuthenticatedAs($employee);
    }

    public function test_authenticator_and_recovery_codes_are_optional_and_single_use(): void
    {
        $employee = User::factory()->create();
        $mfa = app(MfaService::class);
        $method = $mfa->beginTotpEnrollment($employee);
        $codes = $mfa->confirmTotp($employee, app(TotpService::class)->currentCode($method->secret_encrypted));

        $this->assertCount(8, $codes);
        $this->post('/login', ['email' => $employee->email, 'password' => 'password'])->assertRedirect('/mfa/challenge');
        $this->post('/mfa/challenge/method', ['method' => 'totp']);
        $this->post('/mfa/challenge/verify', ['code' => app(TotpService::class)->currentCode($method->secret_encrypted)])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($employee);

        $this->post('/logout');
        $this->post('/login', ['email' => $employee->email, 'password' => 'password'])->assertRedirect('/mfa/challenge');
        $this->post('/mfa/challenge/verify', ['code' => $codes[0], 'use_recovery' => 1])
            ->assertRedirect('/')
            ->assertCookieExpired('u_mail_signed_out');
        $this->post('/logout');
        $this->post('/login', ['email' => $employee->email, 'password' => 'password'])->assertRedirect('/mfa/challenge');
        $this->post('/mfa/challenge/verify', ['code' => $codes[0], 'use_recovery' => 1])->assertSessionHasErrors('code');
    }

    public function test_both_mfa_methods_are_offered_but_email_is_not_offered_to_totp_only_account(): void
    {
        $employee = User::factory()->create();
        $mfa = app(MfaService::class);
        $method = $mfa->beginTotpEnrollment($employee);
        $mfa->confirmTotp($employee, app(TotpService::class)->currentCode($method->secret_encrypted));

        $this->post('/login', ['email' => $employee->email, 'password' => 'password']);
        $this->get('/mfa/challenge')->assertSee('Authenticator app')->assertDontSee('Email code');

        $mfa->enableEmail($employee);
        $this->get('/mfa/challenge')->assertSee('Authenticator app')->assertSee('Email code');
    }

    public function test_sensitive_admin_action_requires_confirmation_and_admin_idle_session_expires(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/employees', ['name' => 'New', 'email' => 'new@utica.test'])
            ->assertRedirect('/confirm-password');
        $this->assertDatabaseMissing('users', ['email' => 'new@utica.test']);

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post('/admin/employees', ['name' => 'New', 'email' => 'new@utica.test'])
            ->assertSessionHas('status');

        $this->withSession(['admin_last_activity' => now()->subMinutes(16)->timestamp])
            ->get('/admin/employees')
            ->assertRedirect('/'.trim(config('security.admin_login_path'), '/'));
        $this->assertGuest();
    }

    public function test_security_headers_and_audit_viewer_are_protected(): void
    {
        config(['owner.email' => 'owner@utica.test']);
        $owner = User::factory()->create(['email' => 'owner@utica.test', 'role' => 'admin']);
        $employee = User::factory()->create();

        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy')
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertHeader('Vary', 'Cookie');
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443])->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://u-mail.test/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->get('/admin/login')->assertNotFound();
        $this->get('/'.trim(config('security.admin_login_path'), '/'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertHeader('Vary', 'Cookie')
            ->assertDontSee('Keep me signed in');

        $this->actingAs($employee)->get('/owner/security-events')->assertForbidden();
        $this->actingAs($owner)->get('/owner/security-events')->assertOk();
        $this->assertGreaterThanOrEqual(1, SecurityEvent::count());
    }

    public function test_admin_and_owner_mfa_return_to_their_role_destinations_after_hidden_login(): void
    {
        Notification::fake();
        config(['owner.email' => 'owner@utica.test']);
        $adminLoginPath = '/'.trim(config('security.admin_login_path'), '/');
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['email' => 'owner@utica.test', 'role' => 'admin']);
        $mfa = app(MfaService::class);
        $mfa->enableEmail($admin);
        $mfa->enableEmail($owner);

        $this->post($adminLoginPath, ['email' => $admin->email, 'password' => 'password', 'remember' => 'on'])
            ->assertRedirect('/mfa/challenge');
        $this->post('/mfa/challenge/method', ['method' => 'email'])->assertSessionHas('status');
        $adminCode = Notification::sent($admin, MfaCodeNotification::class)->last()->code;
        $this->post('/mfa/challenge/verify', ['code' => $adminCode])->assertRedirect('/admin/employees');
        $this->assertAuthenticatedAs($admin);

        $this->post('/logout');
        $this->post($adminLoginPath, ['email' => $owner->email, 'password' => 'password', 'remember' => 'on'])
            ->assertRedirect('/mfa/challenge');
        $this->post('/mfa/challenge/method', ['method' => 'email'])->assertSessionHas('status');
        $ownerCode = Notification::sent($owner, MfaCodeNotification::class)->last()->code;
        $this->post('/mfa/challenge/verify', ['code' => $ownerCode])->assertRedirect('/owner/credentials');
        $this->assertAuthenticatedAs($owner);
    }

    public function test_repeated_login_failures_escalate_to_turnstile(): void
    {
        $employee = User::factory()->create();

        for ($attempt = 0; $attempt < config('security.turnstile.failure_threshold'); $attempt++) {
            $this->post('/login', ['email' => $employee->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('email');
        }

        $this->assertTrue((bool) session('turnstile_required'));
    }

    public function test_email_mfa_codes_are_hashed_expire_lock_and_obey_send_limits(): void
    {
        Notification::fake();
        $employee = User::factory()->create();
        $mfa = app(MfaService::class);
        $mfa->enableEmail($employee);
        $mfa->issueEmailChallenge($employee);

        $firstCode = Notification::sent($employee, MfaCodeNotification::class)->last()->code;
        $firstChallenge = $employee->mfaChallenges()->latest()->firstOrFail();
        $this->assertNotSame($firstCode, $firstChallenge->token_hash);
        $this->assertNotSame(hash('sha256', $firstCode), $firstChallenge->token_hash);
        $this->assertTrue(Hash::check($firstCode, $firstChallenge->token_hash));
        $firstChallenge->update(['expires_at' => now()->subSecond()]);

        try {
            $mfa->consumeEmailChallenge($employee, $firstCode);
            $this->fail('An expired email MFA code was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        RateLimiter::clear('mfa-email-send:'.$employee->id);
        $mfa->issueEmailChallenge($employee);
        $validCode = Notification::sent($employee, MfaCodeNotification::class)->last()->code;
        $challenge = $employee->mfaChallenges()->latest()->firstOrFail();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $mfa->consumeEmailChallenge($employee, '000000');
            } catch (ValidationException) {
                // Expected while the challenge counts failures.
            }
        }
        $this->assertSame(5, $challenge->fresh()->failed_attempts);
        $this->expectException(ValidationException::class);
        $mfa->consumeEmailChallenge($employee, $validCode);
    }

    public function test_email_mfa_resend_invalidates_previous_code_and_limits_fourth_send(): void
    {
        Notification::fake();
        $employee = User::factory()->create();
        $mfa = app(MfaService::class);
        $mfa->enableEmail($employee);

        $mfa->issueEmailChallenge($employee);
        $firstChallenge = $employee->mfaChallenges()->latest()->firstOrFail();
        $mfa->issueEmailChallenge($employee);
        $this->assertNotNull($firstChallenge->fresh()->used_at);
        $mfa->issueEmailChallenge($employee);

        $this->expectException(ValidationException::class);
        $mfa->issueEmailChallenge($employee);
    }

    public function test_reset_tokens_expire_after_fifteen_minutes_and_lock_after_five_failures(): void
    {
        $employee = User::factory()->create();
        $tokens = app(AccountTokenService::class);
        $code = $tokens->issue($employee, 'reset');
        $token = AccountToken::latest('id')->firstOrFail();

        $this->assertLessThanOrEqual(15 * 60, now()->diffInSeconds($token->expires_at, false));
        $this->assertNotSame($code, $token->token_hash);
        $this->assertNotSame(hash('sha256', $code), $token->token_hash);
        $this->assertTrue(Hash::check($code, $token->token_hash));
        $this->assertArrayNotHasKey('token_hash', $token->toArray());
        $token->update(['expires_at' => now()->subSecond()]);
        try {
            $tokens->consume($employee, 'reset', $code);
            $this->fail('An expired reset code was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $validCode = $tokens->issue($employee, 'reset');
        $token = AccountToken::latest('id')->firstOrFail();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $tokens->consume($employee, 'reset', '000000');
            } catch (ValidationException) {
                // Expected while the token counts failures.
            }
        }
        $this->assertSame(5, $token->fresh()->failed_attempts);
        $this->expectException(ValidationException::class);
        $tokens->consume($employee, 'reset', $validCode);
    }

    public function test_legacy_sha_short_codes_remain_consumable_until_expiry(): void
    {
        $employee = User::factory()->create();
        $resetCode = '123456';
        $mfaCode = '654321';

        $token = AccountToken::create([
            'user_id' => $employee->id,
            'purpose' => 'reset',
            'token_hash' => hash('sha256', $resetCode),
            'expires_at' => now()->addMinutes(15),
        ]);
        app(AccountTokenService::class)->consume($employee, 'reset', $resetCode);
        $this->assertNotNull($token->fresh()->used_at);

        $challenge = MfaChallenge::create([
            'user_id' => $employee->id,
            'type' => 'email',
            'token_hash' => hash('sha256', $mfaCode),
            'expires_at' => now()->addMinutes(10),
        ]);
        app(MfaService::class)->consumeEmailChallenge($employee, $mfaCode);
        $this->assertNotNull($challenge->fresh()->used_at);
    }

    public function test_admin_mfa_reset_is_authorized_audited_and_revokes_sessions(): void
    {
        config(['owner.email' => 'owner@utica.test']);
        $owner = User::factory()->create(['email' => 'owner@utica.test', 'role' => 'admin']);
        $admin = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create();
        $mfa = app(MfaService::class);
        $mfa->enableEmail($employee);
        $mfa->enableEmail($admin);
        DB::table('sessions')->insert([
            'id' => 'employee-mfa-session',
            'user_id' => $employee->id,
            'payload' => 'encrypted-session-payload',
            'last_activity' => time(),
        ]);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/admin/employees/{$employee->id}/mfa-reset", ['reason' => 'Verified employee recovery request'])
            ->assertSessionHas('status');
        $this->assertFalse($employee->fresh()->hasMfa());
        $this->assertDatabaseMissing('sessions', ['id' => 'employee-mfa-session']);
        $this->assertDatabaseHas('security_events', [
            'event' => 'admin.mfa_reset',
            'actor_id' => $admin->id,
            'target_user_id' => $employee->id,
        ]);

        $this->post("/admin/employees/{$admin->id}/mfa-reset", ['reason' => 'Attempted self reset'])
            ->assertStatus(422);

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/admin/employees/{$admin->id}/mfa-reset", ['reason' => 'Owner verified administrator identity'])
            ->assertSessionHas('status');
        $this->assertFalse($admin->fresh()->hasMfa());
    }

    public function test_user_can_change_password_and_other_sessions_are_revoked(): void
    {
        $employee = User::factory()->create();
        DB::table('sessions')->insert([
            'id' => 'other-device-session',
            'user_id' => $employee->id,
            'payload' => 'encrypted-session-payload',
            'last_activity' => time(),
        ]);

        $this->actingAs($employee)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/security/password', [
                'current_password' => 'password',
                'password' => 'NewStrongPass123!',
                'password_confirmation' => 'NewStrongPass123!',
            ])
            ->assertSessionHas('status')
            ->assertSessionMissing('auth.password_confirmed_at');

        $employee->refresh();
        $this->assertTrue(Hash::check('NewStrongPass123!', $employee->password));
        $this->assertSame('NewStrongPass123!', $employee->credential->password_encrypted);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
        $this->assertDatabaseHas('security_events', [
            'event' => 'password.changed',
            'actor_id' => $employee->id,
            'target_user_id' => $employee->id,
        ]);
        $this->assertAuthenticatedAs($employee);
    }

    public function test_password_change_rejects_wrong_current_weak_and_reused_passwords(): void
    {
        $employee = User::factory()->create(['password' => 'ExistingStrong123!']);

        $this->actingAs($employee)->post('/security/password', [
            'current_password' => 'wrong-password',
            'password' => 'DifferentStrong123!',
            'password_confirmation' => 'DifferentStrong123!',
        ])->assertSessionHasErrors('current_password');
        $this->assertDatabaseHas('security_events', ['event' => 'password.change_failed', 'actor_id' => $employee->id]);

        $this->post('/security/password', [
            'current_password' => 'ExistingStrong123!',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertSessionHasErrors('password');

        $this->post('/security/password', [
            'current_password' => 'ExistingStrong123!',
            'password' => 'ExistingStrong123!',
            'password_confirmation' => 'ExistingStrong123!',
        ])->assertSessionHasErrors('password');
    }
}
