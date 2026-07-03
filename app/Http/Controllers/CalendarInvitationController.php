<?php

namespace App\Http\Controllers;

use App\Http\Middleware\PreventBackForwardCaching;
use App\Models\CalendarEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CalendarInvitationController extends Controller
{
    public function show(string $token)
    {
        $event = $this->eventForToken($token);

        return response()
            ->view('calendar.invitation', [
                'event' => $event,
                'token' => $token,
            ])
            ->header('Cache-Control', PreventBackForwardCaching::CACHE_CONTROL)
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0')
            ->header('Vary', 'Cookie', false);
    }

    public function generate(CalendarEvent $calendarEvent)
    {
        Gate::authorize('invite', $calendarEvent);

        return $this->issueLink($calendarEvent, 'Calendar invitation link created.');
    }

    public function regenerate(CalendarEvent $calendarEvent)
    {
        Gate::authorize('invite', $calendarEvent);

        return $this->issueLink($calendarEvent, 'Calendar invitation link regenerated.');
    }

    public function revoke(CalendarEvent $calendarEvent)
    {
        Gate::authorize('invite', $calendarEvent);
        $calendarEvent->stopActiveAcceptedCopySyncs();
        $calendarEvent->forceFill([
            'invitation_token_hash' => null,
            'invitation_revoked_at' => now(),
        ])->save();

        return redirect()
            ->route('calendar.index', ['month' => $calendarEvent->starts_at->format('Y-m'), 'filter' => CalendarEvent::SCOPE_PERSONAL])
            ->with('status', 'Calendar invitation link revoked.');
    }

    public function accept(Request $request, string $token)
    {
        $event = $this->eventForToken($token);
        abort_if($event->owner_id === $request->user()->id, 403);

        CalendarEvent::updateOrCreate(
            [
                'source_event_id' => $event->id,
                'owner_id' => $request->user()->id,
            ],
            [
                'scope' => CalendarEvent::SCOPE_PERSONAL,
                'created_by' => $event->owner_id,
                'title' => $event->title,
                'starts_at' => $event->starts_at,
                'ends_at' => $event->ends_at,
                'location' => $event->location,
                'notes' => $event->notes,
                'source_sync_stopped_at' => null,
            ],
        );

        return redirect()
            ->route('calendar.index', ['month' => $event->starts_at->format('Y-m'), 'filter' => CalendarEvent::SCOPE_PERSONAL])
            ->with('status', 'Invitation accepted and added to your calendar.');
    }

    private function issueLink(CalendarEvent $calendarEvent, string $status)
    {
        if ($calendarEvent->hasActiveInvitation()) {
            $calendarEvent->stopActiveAcceptedCopySyncs();
        }

        $token = Str::random(64);
        $calendarEvent->forceFill([
            'invitation_token_hash' => CalendarEvent::invitationTokenHash($token),
            'invitation_created_at' => now(),
            'invitation_revoked_at' => null,
        ])->save();

        return redirect()
            ->route('calendar.index', ['month' => $calendarEvent->starts_at->format('Y-m'), 'filter' => CalendarEvent::SCOPE_PERSONAL])
            ->with('calendar_invitation_link', [
                'event_id' => $calendarEvent->id,
                'url' => route('calendar.invitations.show', $token),
            ])
            ->with('status', $status);
    }

    private function eventForToken(string $token): CalendarEvent
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token), 404);

        return CalendarEvent::query()
            ->where('scope', CalendarEvent::SCOPE_PERSONAL)
            ->whereNull('source_event_id')
            ->whereNull('invitation_revoked_at')
            ->where('invitation_token_hash', CalendarEvent::invitationTokenHash($token))
            ->with(['owner', 'creator'])
            ->firstOrFail();
    }
}
