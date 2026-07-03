<?php

namespace Tests\Feature;

use App\Http\Controllers\OnboardingTutorialController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTutorialTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_without_completed_tour_receives_auto_start_layout(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/')
            ->assertOk()
            ->assertSee('data-onboarding-tour-available="true"', false)
            ->assertSee('data-onboarding-tour-auto-start="true"', false)
            ->assertSee('data-onboarding-tour-version="'.OnboardingTutorialController::CURRENT_VERSION.'"', false)
            ->assertSee('U-Mail guide')
            ->assertSee('Step 1 of 7');
    }

    public function test_employee_who_completed_tour_does_not_auto_start_again(): void
    {
        $employee = User::factory()->create([
            'onboarding_tour_completed_at' => now(),
            'onboarding_tour_version' => OnboardingTutorialController::CURRENT_VERSION,
        ]);

        $this->actingAs($employee)->get('/')
            ->assertOk()
            ->assertSee('data-onboarding-tour-available="true"', false)
            ->assertSee('data-onboarding-tour-auto-start="false"', false)
            ->assertSee('U-Mail guide');
    }

    public function test_admin_and_owner_do_not_receive_employee_tour(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'admin', 'email' => 'owner@example.test']);
        config(['owner.email' => 'owner@example.test']);

        $this->actingAs($admin)->get('/')
            ->assertOk()
            ->assertSee('data-onboarding-tour-available="false"', false)
            ->assertSee('data-onboarding-tour-auto-start="false"', false)
            ->assertDontSee('U-Mail guide');

        $this->actingAs($owner)->get('/')
            ->assertOk()
            ->assertSee('data-onboarding-tour-available="false"', false)
            ->assertSee('data-onboarding-tour-auto-start="false"', false)
            ->assertDontSee('U-Mail guide');
    }

    public function test_employee_can_finish_or_skip_tour_and_admin_cannot_update_it(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($employee)->postJson('/tutorial/onboarding/complete', ['action' => 'skip'])
            ->assertOk()
            ->assertJsonPath('completed', true)
            ->assertJsonPath('action', 'skip')
            ->assertJsonPath('version', OnboardingTutorialController::CURRENT_VERSION);

        $employee->refresh();
        $this->assertNotNull($employee->onboarding_tour_completed_at);
        $this->assertSame(OnboardingTutorialController::CURRENT_VERSION, $employee->onboarding_tour_version);

        $this->actingAs($admin)->postJson('/tutorial/onboarding/complete', ['action' => 'finish'])
            ->assertForbidden();

        $this->assertNull($admin->fresh()->onboarding_tour_completed_at);
    }

    public function test_account_settings_allows_employee_to_restart_tutorial(): void
    {
        $employee = User::factory()->create([
            'onboarding_tour_completed_at' => now(),
            'onboarding_tour_version' => OnboardingTutorialController::CURRENT_VERSION,
        ]);

        $this->actingAs($employee)->get('/settings')
            ->assertOk()
            ->assertSee('Restart tutorial')
            ->assertSee('data-onboarding-restart', false);
    }
}
