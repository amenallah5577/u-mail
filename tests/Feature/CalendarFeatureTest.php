<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_requires_authentication_and_renders_month_navigation(): void
    {
        $employee = User::factory()->create();

        $this->get('/calendar')->assertRedirect('/login');

        $this->actingAs($employee)->get('/calendar?month=2026-07&filter=all')
            ->assertOk()
            ->assertSee('Calendar')
            ->assertSee('July 2026')
            ->assertSee('Previous')
            ->assertSee('Today')
            ->assertSee('Next')
            ->assertSee('month=2026-06', false)
            ->assertSee('month=2026-08', false)
            ->assertSee('All')
            ->assertSee('Shared')
            ->assertSee('Personal')
            ->assertSee('Mon');
    }

    public function test_users_see_shared_and_own_personal_events_but_not_other_personal_events(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        CalendarEvent::create([
            'scope' => CalendarEvent::SCOPE_SHARED,
            'created_by' => $admin->id,
            'title' => 'Shared Planning Day',
            'starts_at' => '2026-07-10 09:00:00',
            'ends_at' => '2026-07-10 10:00:00',
        ]);
        CalendarEvent::create([
            'scope' => CalendarEvent::SCOPE_PERSONAL,
            'owner_id' => $employee->id,
            'created_by' => $employee->id,
            'title' => 'My Quiet Block',
            'starts_at' => '2026-07-11 09:00:00',
            'ends_at' => '2026-07-11 10:00:00',
        ]);
        CalendarEvent::create([
            'scope' => CalendarEvent::SCOPE_PERSONAL,
            'owner_id' => $other->id,
            'created_by' => $other->id,
            'title' => 'Other Secret Block',
            'starts_at' => '2026-07-12 09:00:00',
            'ends_at' => '2026-07-12 10:00:00',
        ]);

        $this->actingAs($employee)->get('/calendar?month=2026-07')
            ->assertOk()
            ->assertSee('Shared Planning Day')
            ->assertSee('My Quiet Block')
            ->assertDontSee('Other Secret Block');

        $this->get('/calendar?month=2026-07&filter=shared')
            ->assertOk()
            ->assertSee('Shared Planning Day')
            ->assertDontSee('My Quiet Block');

        $this->get('/calendar?month=2026-07&filter=personal')
            ->assertOk()
            ->assertSee('My Quiet Block')
            ->assertDontSee('Shared Planning Day');
    }

    public function test_users_manage_own_personal_events_but_cannot_manage_shared_or_other_personal_events(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($employee)->post('/calendar/events', [
            'scope' => CalendarEvent::SCOPE_PERSONAL,
            'title' => 'Personal Standup',
            'starts_at' => '2026-07-13T09:00',
            'ends_at' => '2026-07-13T09:30',
            'location' => 'Desk',
            'notes' => 'Prep weekly notes',
        ])->assertRedirect('/calendar?month=2026-07&filter=personal');

        $personal = CalendarEvent::where('title', 'Personal Standup')->firstOrFail();
        $this->assertSame($employee->id, $personal->owner_id);
        $this->assertSame($employee->id, $personal->created_by);

        $this->post('/calendar/events', [
            'scope' => CalendarEvent::SCOPE_SHARED,
            'title' => 'Employee Shared Attempt',
            'starts_at' => '2026-07-13T10:00',
            'ends_at' => '2026-07-13T11:00',
        ])->assertForbidden();

        $this->patch("/calendar/events/{$personal->id}", [
            'title' => 'Personal Standup Updated',
            'starts_at' => '2026-07-13T09:15',
            'ends_at' => '2026-07-13T09:45',
        ])->assertSessionHas('status');
        $this->assertDatabaseHas('calendar_events', ['id' => $personal->id, 'title' => 'Personal Standup Updated']);

        $otherPersonal = CalendarEvent::create([
            'scope' => CalendarEvent::SCOPE_PERSONAL,
            'owner_id' => $other->id,
            'created_by' => $other->id,
            'title' => 'Other Appointment',
            'starts_at' => '2026-07-14 09:00:00',
            'ends_at' => '2026-07-14 10:00:00',
        ]);
        $shared = CalendarEvent::create([
            'scope' => CalendarEvent::SCOPE_SHARED,
            'created_by' => $admin->id,
            'title' => 'Managed Shared Event',
            'starts_at' => '2026-07-15 09:00:00',
            'ends_at' => '2026-07-15 10:00:00',
        ]);

        $this->patch("/calendar/events/{$otherPersonal->id}", [
            'title' => 'Other Appointment Changed',
            'starts_at' => '2026-07-14T09:00',
            'ends_at' => '2026-07-14T10:00',
        ])->assertForbidden();
        $this->delete("/calendar/events/{$otherPersonal->id}")->assertForbidden();

        $this->patch("/calendar/events/{$shared->id}", [
            'title' => 'Managed Shared Event Changed',
            'starts_at' => '2026-07-15T09:00',
            'ends_at' => '2026-07-15T10:00',
        ])->assertForbidden();
        $this->delete("/calendar/events/{$shared->id}")->assertForbidden();
    }

    public function test_admins_can_manage_shared_events(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/calendar/events', [
            'scope' => CalendarEvent::SCOPE_SHARED,
            'title' => 'UTICA Review',
            'starts_at' => '2026-07-16T14:00',
            'ends_at' => '2026-07-16T15:00',
        ])->assertRedirect('/calendar?month=2026-07&filter=shared');

        $event = CalendarEvent::where('title', 'UTICA Review')->firstOrFail();
        $this->assertNull($event->owner_id);
        $this->assertSame($admin->id, $event->created_by);

        $this->patch("/calendar/events/{$event->id}", [
            'title' => 'UTICA Review Updated',
            'starts_at' => '2026-07-16T14:30',
            'ends_at' => '2026-07-16T15:30',
        ])->assertSessionHas('status');
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'title' => 'UTICA Review Updated']);

        $this->delete("/calendar/events/{$event->id}")->assertSessionHas('status');
        $this->assertDatabaseMissing('calendar_events', ['id' => $event->id]);
    }

    public function test_personal_event_owner_can_generate_view_revoke_and_regenerate_invitation_link(): void
    {
        $owner = User::factory()->create([
            'email' => 'private.owner@example.test',
            'public_email' => 'owner@u-mail.local',
        ]);
        $event = CalendarEvent::create([
            'scope' => CalendarEvent::SCOPE_PERSONAL,
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            'title' => 'Private Planning Invite',
            'starts_at' => '2026-07-18 09:00:00',
            'ends_at' => '2026-07-18 10:00:00',
            'location' => 'Office',
            'notes' => 'Bring the agenda',
        ]);

        $response = $this->actingAs($owner)->post("/calendar/events/{$event->id}/invitation")
            ->assertRedirect('/calendar?month=2026-07&filter=personal')
            ->assertSessionHas('calendar_invitation_link');
        $token = $this->invitationTokenFromResponse($response);

        $event->refresh();
        $this->assertNotNull($event->invitation_created_at);
        $this->assertNotSame($token, $event->invitation_token_hash);
        $this->assertSame(CalendarEvent::invitationTokenHash($token), $event->invitation_token_hash);

        $this->get('/calendar?month=2026-07&filter=personal')
            ->assertOk()
            ->assertSee('Invitation link')
            ->assertSee(route('calendar.invitations.show', $token), false);

        $this->post('/logout');
        $this->get("/calendar/invitations/{$token}")
            ->assertOk()
            ->assertSee('Private Planning Invite')
            ->assertSee('Office')
            ->assertSee('owner@u-mail.local')
            ->assertDontSee('private.owner@example.test')
            ->assertSee('Sign in to accept');

        $this->actingAs($owner)->delete("/calendar/events/{$event->id}/invitation")
            ->assertRedirect('/calendar?month=2026-07&filter=personal')
            ->assertSessionHas('status');
        $this->assertNull($event->fresh()->invitation_token_hash);
        $this->get("/calendar/invitations/{$token}")->assertNotFound();

        $regenerated = $this->actingAs($owner)->post("/calendar/events/{$event->id}/invitation/regenerate")
            ->assertRedirect('/calendar?month=2026-07&filter=personal')
            ->assertSessionHas('calendar_invitation_link');
        $newToken = $this->invitationTokenFromResponse($regenerated);
        $this->assertNotSame($token, $newToken);
        $this->get("/calendar/invitations/{$newToken}")->assertOk();
    }

    public function test_invitation_permissions_reject_shared_other_personal_and_accepted_copy_events(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $personal = CalendarEvent::create([
            'scope' => CalendarEvent::SCOPE_PERSONAL,
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            'title' => 'Owner Personal',
            'starts_at' => '2026-07-18 09:00:00',
            'ends_at' => '2026-07-18 10:00:00',
        ]);
        $shared = CalendarEvent::create([
            'scope' => CalendarEvent::SCOPE_SHARED,
            'created_by' => $admin->id,
            'title' => 'Shared Event',
            'starts_at' => '2026-07-18 11:00:00',
            'ends_at' => '2026-07-18 12:00:00',
        ]);
        $acceptedCopy = CalendarEvent::create([
            'scope' => CalendarEvent::SCOPE_PERSONAL,
            'owner_id' => $other->id,
            'created_by' => $owner->id,
            'source_event_id' => $personal->id,
            'title' => 'Accepted Copy',
            'starts_at' => '2026-07-18 09:00:00',
            'ends_at' => '2026-07-18 10:00:00',
        ]);

        $this->actingAs($other)->post("/calendar/events/{$personal->id}/invitation")->assertForbidden();
        $this->actingAs($admin)->post("/calendar/events/{$shared->id}/invitation")->assertForbidden();
        $this->actingAs($other)->post("/calendar/events/{$acceptedCopy->id}/invitation")->assertForbidden();
    }

    public function test_signed_in_users_accept_invitation_once_and_synced_copy_is_read_only_until_revoked(): void
    {
        $owner = User::factory()->create(['public_email' => 'organizer@u-mail.local']);
        $invitee = User::factory()->create();
        $event = CalendarEvent::create([
            'scope' => CalendarEvent::SCOPE_PERSONAL,
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            'title' => 'Invitation Source',
            'starts_at' => '2026-07-19 09:00:00',
            'ends_at' => '2026-07-19 10:00:00',
        ]);
        $token = $this->invitationTokenFromResponse(
            $this->actingAs($owner)->post("/calendar/events/{$event->id}/invitation")
        );

        $this->actingAs($owner)->post("/calendar/invitations/{$token}/accept")->assertForbidden();

        $this->actingAs($invitee)->post("/calendar/invitations/{$token}/accept")
            ->assertRedirect('/calendar?month=2026-07&filter=personal')
            ->assertSessionHas('status');
        $this->actingAs($invitee)->post("/calendar/invitations/{$token}/accept")
            ->assertRedirect('/calendar?month=2026-07&filter=personal');

        $this->assertSame(1, CalendarEvent::where('source_event_id', $event->id)->where('owner_id', $invitee->id)->count());
        $copy = CalendarEvent::where('source_event_id', $event->id)->where('owner_id', $invitee->id)->firstOrFail();

        $this->patch("/calendar/events/{$copy->id}", [
            'title' => 'Invitee Edit Attempt',
            'starts_at' => '2026-07-19T09:00',
            'ends_at' => '2026-07-19T10:00',
        ])->assertForbidden();

        $this->delete("/calendar/events/{$copy->id}")
            ->assertRedirect('/calendar?month=2026-07&filter=personal')
            ->assertSessionHas('status');
        $this->assertDatabaseMissing('calendar_events', ['id' => $copy->id]);
    }

    public function test_owner_edits_sync_until_revoke_and_source_delete_removes_accepted_copies(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $event = CalendarEvent::create([
            'scope' => CalendarEvent::SCOPE_PERSONAL,
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            'title' => 'Sync Source',
            'starts_at' => '2026-07-20 09:00:00',
            'ends_at' => '2026-07-20 10:00:00',
        ]);
        $token = $this->invitationTokenFromResponse(
            $this->actingAs($owner)->post("/calendar/events/{$event->id}/invitation")
        );
        $this->actingAs($invitee)->post("/calendar/invitations/{$token}/accept");
        $copy = CalendarEvent::where('source_event_id', $event->id)->where('owner_id', $invitee->id)->firstOrFail();

        $this->actingAs($owner)->patch("/calendar/events/{$event->id}", [
            'title' => 'Synced Source Update',
            'starts_at' => '2026-07-20T11:00',
            'ends_at' => '2026-07-20T12:00',
            'location' => 'Updated Room',
            'notes' => 'Updated notes',
        ])->assertSessionHas('status');

        $copy->refresh();
        $this->assertSame('Synced Source Update', $copy->title);
        $this->assertSame('Updated Room', $copy->location);
        $this->assertNull($copy->source_sync_stopped_at);

        $this->delete("/calendar/events/{$event->id}/invitation")->assertSessionHas('status');
        $this->get("/calendar/invitations/{$token}")->assertNotFound();
        $this->assertNotNull($copy->fresh()->source_sync_stopped_at);

        $this->patch("/calendar/events/{$event->id}", [
            'title' => 'No Longer Synced',
            'starts_at' => '2026-07-20T13:00',
            'ends_at' => '2026-07-20T14:00',
        ])->assertSessionHas('status');
        $this->assertSame('Synced Source Update', $copy->fresh()->title);

        $this->actingAs($invitee)->patch("/calendar/events/{$copy->id}", [
            'title' => 'Invitee Own Copy',
            'starts_at' => '2026-07-20T11:00',
            'ends_at' => '2026-07-20T12:00',
        ])->assertSessionHas('status');
        $this->assertSame('Invitee Own Copy', $copy->fresh()->title);

        $this->actingAs($owner)->delete("/calendar/events/{$event->id}")->assertSessionHas('status');
        $this->assertDatabaseMissing('calendar_events', ['id' => $event->id]);
        $this->assertDatabaseMissing('calendar_events', ['id' => $copy->id]);
    }

    public function test_calendar_event_validation_requires_valid_scope_title_and_times(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->post('/calendar/events', [
            'scope' => 'team',
            'title' => '',
            'starts_at' => '',
            'ends_at' => '',
        ])->assertSessionHasErrors(['scope', 'title', 'starts_at', 'ends_at']);

        $this->post('/calendar/events', [
            'scope' => CalendarEvent::SCOPE_PERSONAL,
            'title' => 'Backwards Event',
            'starts_at' => '2026-07-17T10:00',
            'ends_at' => '2026-07-17T09:00',
        ])->assertSessionHasErrors('ends_at');
    }

    private function invitationTokenFromResponse($response): string
    {
        $payload = $response->baseResponse->getSession()->get('calendar_invitation_link');
        $path = parse_url($payload['url'], PHP_URL_PATH);

        return basename($path);
    }
}
