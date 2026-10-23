<?php

namespace Tests\Feature;

use App\Models\AccountToken;
use App\Models\User;
use App\Services\AccountTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PasswordRecoverySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_and_reveal_endpoints_are_gone_even_for_the_owner(): void
    {
        config(['owner.email' => 'owner@example.test']);
        $owner = User::factory()->create(['email' => 'owner@example.test', 'role' => 'admin']);

        $this->assertFalse(Schema::hasTable('account_credentials'));
        $this->get('/owner/credentials')->assertNotFound();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
        $this->get('/owner/credentials')->assertNotFound();
        $this->post('/owner/credentials/'.$owner->id.'/reveal')->assertNotFound();
        $this->get('/admin/employees')->assertOk()->assertDontSee('/owner/credentials');
    }

    public function test_upgrade_purges_legacy_copies_and_rollback_cannot_restore_them(): void
    {
        $user = User::factory()->create();
        $passwordHash = $user->password;
        $migration = require database_path('migrations/2026_10_22_000001_drop_account_credentials_table.php');
        $migration->down();
        DB::table('account_credentials')->insert([
            'user_id' => $user->id,
            'password_encrypted' => encrypt('legacy-password'),
        ]);

        $migration->up();
        $this->assertFalse(Schema::hasTable('account_credentials'));
        $this->assertSame($passwordHash, $user->fresh()->password);
        $migration->down();
        $this->assertDatabaseCount('account_credentials', 0);
        $migration->up();
    }

    public function test_reset_code_is_hashed_single_use_and_revokes_sessions_and_remember_tokens(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        DB::table('sessions')->insert([
            'id' => 'old-session', 'user_id' => $user->id,
            'payload' => 'test', 'last_activity' => time(),
        ]);
        $code = app(AccountTokenService::class)->issue($user, 'reset');
        $token = AccountToken::firstOrFail();
        $this->assertNotSame($code, $token->token_hash);
        $this->assertTrue(Hash::check($code, $token->token_hash));
        $payload = [
            'email' => $user->public_email, 'code' => $code,
            'password' => 'NewStrongPass123!', 'password_confirmation' => 'NewStrongPass123!',
        ];
        $this->post('/reset-password', $payload)->assertRedirect('/login');
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->assertNull($user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertNotNull($token->fresh()->used_at);
        $this->post('/reset-password', $payload)->assertSessionHasErrors('code');
        $this->assertFalse(Schema::hasTable('account_credentials'));
    }

    public function test_reissued_reset_code_invalidates_the_previous_code(): void
    {
        $user = User::factory()->create();
        $tokens = app(AccountTokenService::class);
        $tokens->issue($user, 'reset');
        $tokens->issue($user, 'reset');
        // Distinct fixed codes make this regression independent of random collisions.
        $records = AccountToken::orderBy('id')->get();
        $records[0]->update(['token_hash' => Hash::make('111111')]);
        $records[1]->update(['token_hash' => Hash::make('222222')]);
        $payload = ['email' => $user->public_email, 'password' => 'NewStrongPass123!', 'password_confirmation' => 'NewStrongPass123!'];
        $this->post('/reset-password', $payload + ['code' => '111111'])->assertSessionHasErrors('code');
        $this->post('/reset-password', $payload + ['code' => '222222'])->assertRedirect('/login');
    }

    public function test_reset_request_does_not_disclose_whether_an_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $known = $this->post('/reset-password/request', ['email' => $user->public_email]);
        $message = session('status');
        $known->assertSessionHas('status');
        $this->post('/reset-password/request', ['email' => 'missing@u-mail.local'])
            ->assertSessionHas('status', $message);
    }
}
