<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_menu_and_account_pages_are_available_to_active_users(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0')
            ->assertHeader('Vary', 'Cookie')
            ->assertSee('Show profile')
            ->assertSee('Account settings')
            ->assertSee('Security');

        $this->get('/settings')->assertOk()->assertSee('Account settings')->assertSee('Show profile')->assertSee('Appearance');
        $this->get('/profile')
            ->assertOk()
            ->assertSee('Your information')
            ->assertSee($employee->public_email)
            ->assertSee('This is your main address for sending, receiving, and signing in.');
    }

    public function test_back_history_after_logout_revalidates_authenticated_pages(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/profile')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertHeader('Vary', 'Cookie');

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/profile')->assertRedirect('/login');
    }

    public function test_user_can_choose_a_signed_in_appearance_preference(): void
    {
        $employee = User::factory()->create();
        $this->assertSame('light', $employee->fresh()->theme_preference);

        $this->actingAs($employee)->get('/settings/appearance')
            ->assertOk()
            ->assertSee('data-saved-theme="light"', false)
            ->assertSee('Light')
            ->assertSee('Dark')
            ->assertSee('System');

        $this->get('/')->assertOk()->assertSee('data-theme-preference="light"', false);

        $this->patch('/settings/appearance', ['theme_preference' => 'dark'])
            ->assertRedirect('/settings/appearance')
            ->assertSessionHas('status');

        $this->assertSame('dark', $employee->fresh()->theme_preference);
        $this->get('/')->assertOk()->assertSee('data-theme-preference="dark"', false);

        $this->patch('/settings/appearance', ['theme_preference' => 'system'])
            ->assertRedirect('/settings/appearance')
            ->assertSessionHas('status');

        $this->assertSame('system', $employee->fresh()->theme_preference);
        $this->get('/')->assertOk()->assertSee('data-theme-preference="system"', false);

        $this->patch('/settings/appearance', ['theme_preference' => 'neon'])->assertSessionHasErrors('theme_preference');
    }

    public function test_user_can_update_only_their_profile_information(): void
    {
        $employee = User::factory()->create(['email' => 'employee@utica.test', 'role' => 'employee']);

        $this->actingAs($employee)->patch('/profile', [
            'name' => 'Updated Employee',
            'phone' => '+216 71 000 000',
            'email' => 'changed@utica.test',
            'role' => 'admin',
        ])->assertSessionHas('status');

        $employee->refresh();
        $this->assertSame('Updated Employee', $employee->name);
        $this->assertSame('+216 71 000 000', $employee->phone);
        $this->assertSame('employee@utica.test', $employee->email);
        $this->assertSame('employee', $employee->role);
        $this->assertDatabaseHas('security_events', [
            'event' => 'profile.updated',
            'actor_id' => $employee->id,
            'target_user_id' => $employee->id,
        ]);
    }

    public function test_directory_exposes_u_mail_address_but_not_private_contact_email(): void
    {
        $viewer = User::factory()->create();
        $employee = User::factory()->create([
            'name' => 'Directory Employee',
            'email' => 'private.directory@example.net',
            'public_email' => 'directory.employee@u-mail.local',
        ]);

        $this->actingAs($viewer)->getJson('/directory?q=Directory')
            ->assertOk()
            ->assertJsonFragment(['email' => $employee->public_email])
            ->assertJsonMissing(['email' => $employee->email]);

        $this->getJson('/directory?q=private.directory@example.net')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_profile_photos_are_private_replaceable_and_removable(): void
    {
        Storage::fake('local');
        $employee = User::factory()->create();

        $this->actingAs($employee)->patch('/profile', [
            'name' => $employee->name,
            'phone' => '',
            'photo' => UploadedFile::fake()->image('first.jpg', 300, 300)->size(300),
        ])->assertSessionHas('status');

        $firstPath = $employee->fresh()->profile_photo_path;
        Storage::disk('local')->assertExists($firstPath);
        $this->get("/profile-photo/{$employee->id}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->patch('/profile', [
            'name' => $employee->name,
            'phone' => '',
            'photo' => UploadedFile::fake()->image('second.png', 300, 300)->size(300),
        ])->assertSessionHas('status');

        $secondPath = $employee->fresh()->profile_photo_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);

        $this->delete('/profile/photo')->assertSessionHas('status');
        Storage::disk('local')->assertMissing($secondPath);
        $this->assertNull($employee->fresh()->profile_photo_path);
    }

    public function test_profile_pages_and_photos_require_authentication(): void
    {
        $employee = User::factory()->create(['profile_photo_path' => 'profile-photos/avatar.jpg']);

        $this->get('/profile')->assertRedirect('/login');
        $this->get('/settings')->assertRedirect('/login');
        $this->get("/profile-photo/{$employee->id}")->assertRedirect('/login');
    }

    public function test_deleting_an_employee_removes_their_private_profile_photo(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['profile_photo_path' => 'profile-photos/employee/avatar.jpg']);
        Storage::disk('local')->put($employee->profile_photo_path, 'private-photo');

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->delete("/admin/employees/{$employee->id}")
            ->assertSessionHas('status');

        Storage::disk('local')->assertMissing($employee->profile_photo_path);
    }
}
