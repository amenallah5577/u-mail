<?php

namespace App\Policies;

use App\Models\CalendarEvent;
use App\Models\User;

class CalendarEventPolicy
{
    public function view(User $user, CalendarEvent $calendarEvent): bool
    {
        return $calendarEvent->isShared() || $calendarEvent->owner_id === $user->id;
    }

    public function create(User $user, string $scope): bool
    {
        return match ($scope) {
            CalendarEvent::SCOPE_SHARED => $user->isAdmin(),
            CalendarEvent::SCOPE_PERSONAL => $user->isActive(),
            default => false,
        };
    }

    public function update(User $user, CalendarEvent $calendarEvent): bool
    {
        if ($calendarEvent->isActiveSyncedCopy()) {
            return false;
        }

        return $this->manage($user, $calendarEvent);
    }

    public function delete(User $user, CalendarEvent $calendarEvent): bool
    {
        return $this->manage($user, $calendarEvent);
    }

    public function invite(User $user, CalendarEvent $calendarEvent): bool
    {
        return $calendarEvent->canHaveInvitation() && $calendarEvent->owner_id === $user->id;
    }

    private function manage(User $user, CalendarEvent $calendarEvent): bool
    {
        if ($calendarEvent->isShared()) {
            return $user->isAdmin();
        }

        return $calendarEvent->owner_id === $user->id;
    }
}
