@extends('layouts.app')
@section('title', 'Calendar')
@section('content')
@php
    $filterLabels = ['all' => 'All', 'shared' => 'Shared', 'personal' => 'Personal'];
    $monthRoute = fn (string $targetMonth, ?string $targetFilter = null) => route('calendar.index', [
        'month' => $targetMonth,
        'filter' => $targetFilter ?? $filter,
    ]);
    $dateTimeValue = fn ($date) => $date->format('Y-m-d\TH:i');
    $defaultStart = $month->setTime(9, 0);
    $defaultEnd = $defaultStart->addHour();
@endphp
<section class="calendar-page">
    <div class="page-heading">
        <div><p class="eyebrow">WORKSPACE</p><h1>Calendar</h1></div>
        <span class="page-count"><b>{{ $agendaEvents->count() }}</b> Events</span>
    </div>

    <div class="calendar-toolbar">
        <div class="calendar-nav">
            <a class="soft-button" href="{{ $monthRoute($previousMonth) }}"><x-icon name="chevron-left" /> Previous</a>
            <a class="soft-button" href="{{ $monthRoute($todayMonth) }}">Today</a>
            <a class="soft-button" href="{{ $monthRoute($nextMonth) }}">Next <x-icon name="chevron-right" /></a>
        </div>
        <div class="calendar-filter-tabs" aria-label="Calendar filters">
            @foreach($filterLabels as $value => $label)
                <a class="{{ $filter === $value ? 'active' : '' }}" href="{{ $monthRoute($month->format('Y-m'), $value) }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>
    <div class="calendar-shell">
        <aside class="calendar-panel">
            <div class="calendar-panel-heading">
                <p class="eyebrow">NEW EVENT</p>
                <h2>{{ $month->format('F Y') }}</h2>
            </div>
            <form class="calendar-event-form" method="POST" action="{{ route('calendar.events.store') }}">
                @csrf
                <label>Scope
                    <select name="scope" required>
                        <option value="personal" @selected(old('scope', 'personal') === 'personal')>Personal</option>
                        @if(auth()->user()->isAdmin())
                            <option value="shared" @selected(old('scope') === 'shared')>Shared UTICA</option>
                        @endif
                    </select>
                </label>
                <label>Title<input name="title" value="{{ old('title') }}" maxlength="160" required></label>
                <label><input type="datetime-local" name="starts_at" value="{{ old('starts_at', $dateTimeValue($defaultStart)) }}" aria-label="Event begins" required></label>
                <label><input type="datetime-local" name="ends_at" value="{{ old('ends_at', $dateTimeValue($defaultEnd)) }}" aria-label="Event finishes" required></label>
                <label>Location<input name="location" value="{{ old('location') }}" maxlength="255"></label>
                <label>Notes<textarea name="notes" rows="5" maxlength="2000">{{ old('notes') }}</textarea></label>
                <button class="primary-button small" type="submit">Create event</button>
            </form>
        </aside>

        <div class="calendar-board">
            <header class="calendar-board-heading">
                <span><strong>{{ $month->format('F') }}</strong><small>{{ $gridStart->format('M j') }} - {{ $gridEnd->format('M j, Y') }}</small></span>
            </header>
            <div class="calendar-weekdays">
                @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                    <span>{{ $weekday }}</span>
                @endforeach
            </div>
            <div class="calendar-month-grid">
                @foreach($weeks as $week)
                    @foreach($week as $day)
                        @php
                            $dateKey = $day['date']->toDateString();
                        @endphp
                        <article class="calendar-day {{ $day['isCurrentMonth'] ? '' : 'outside' }} {{ $day['isToday'] ? 'today' : '' }}">
                            <time datetime="{{ $dateKey }}"><span>{{ $day['date']->format('j') }}</span><small>{{ $day['date']->format('M') }}</small></time>
                            <div class="calendar-day-events">
                                @foreach($eventsByDate[$dateKey] ?? [] as $event)
                                    <a class="calendar-pill {{ $event->scope }}" href="#calendar-event-{{ $event->id }}">{{ $event->title }}</a>
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                @endforeach
            </div>
        </div>
    </div>

    <section class="calendar-agenda">
        <div class="calendar-panel-heading">
            <p class="eyebrow">AGENDA</p>
            <h2>{{ $month->format('F Y') }}</h2>
        </div>
        @if($agendaEvents->isEmpty())
            <div class="empty-state compact-empty calendar-empty"><x-icon name="calendar" /><h2>No events this month</h2><p>The selected calendar has no events in {{ $month->format('F') }}.</p></div>
        @else
            <div class="calendar-agenda-list">
                @foreach($agendaEvents as $event)
                    @php
                        $sessionInvite = session('calendar_invitation_link');
                        $inviteUrl = is_array($sessionInvite) && (int) ($sessionInvite['event_id'] ?? 0) === $event->id
                            ? ($sessionInvite['url'] ?? null)
                            : null;
                    @endphp
                    <article class="calendar-agenda-item {{ $event->scope }}" id="calendar-event-{{ $event->id }}">
                        <div class="calendar-agenda-summary">
                            <span class="calendar-scope-badge {{ $event->scope }}">{{ ucfirst($event->scope) }}</span>
                            @if($event->isActiveSyncedCopy())
                                <span class="calendar-sync-badge">Synced invitation</span>
                            @elseif($event->isAcceptedCopy())
                                <span class="calendar-sync-badge stopped">Invitation copy</span>
                            @endif
                            <h3>{{ $event->title }}</h3>
                            @if($event->location)
                                <p><x-icon name="globe" /> {{ $event->location }}</p>
                            @endif
                            @if($event->notes)
                                <div class="calendar-notes">{{ $event->notes }}</div>
                            @endif
                            <small>Created by {{ $event->creator?->name ?: 'Former user' }}</small>
                        </div>
                        <div class="calendar-agenda-actions">
                            @if($event->isActiveSyncedCopy())
                                <div class="calendar-invite-panel muted">
                                    <strong>Synced from invitation</strong>
                                    <p>This copy follows the organizer's updates until the invitation is revoked.</p>
                                </div>
                            @endif
                            @can('update', $event)
                                <details class="calendar-edit-details">
                                    <summary>Edit</summary>
                                    <form class="calendar-edit-form" method="POST" action="{{ route('calendar.events.update', $event) }}">
                                        @csrf
                                        @method('PATCH')
                                        <label>Title<input name="title" value="{{ old('title', $event->title) }}" maxlength="160" required></label>
                                        <label><input type="datetime-local" name="starts_at" value="{{ old('starts_at', $dateTimeValue($event->starts_at)) }}" aria-label="Event begins" required></label>
                                        <label><input type="datetime-local" name="ends_at" value="{{ old('ends_at', $dateTimeValue($event->ends_at)) }}" aria-label="Event finishes" required></label>
                                        <label>Location<input name="location" value="{{ old('location', $event->location) }}" maxlength="255"></label>
                                        <label>Notes<textarea name="notes" rows="4" maxlength="2000">{{ old('notes', $event->notes) }}</textarea></label>
                                        <button class="primary-button small" type="submit">Save event</button>
                                    </form>
                                </details>
                            @endcan
                            @can('invite', $event)
                                <div class="calendar-invite-panel">
                                    <strong>Invitation link</strong>
                                    @if($event->hasActiveInvitation())
                                        @if($inviteUrl)
                                            <label>Copy once
                                                <span class="calendar-invite-copy-row">
                                                    <input value="{{ $inviteUrl }}" readonly>
                                                    <button class="soft-button" type="button" data-copy-value="{{ $inviteUrl }}">Copy</button>
                                                </span>
                                            </label>
                                        @else
                                            <p>An invitation link is active. Regenerate it to create a new copyable URL.</p>
                                        @endif
                                        <div class="calendar-invite-actions">
                                            <form method="POST" action="{{ route('calendar.events.invitation.regenerate', $event) }}">
                                                @csrf
                                                <button class="soft-button" type="submit">Regenerate</button>
                                            </form>
                                            <form method="POST" action="{{ route('calendar.events.invitation.revoke', $event) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button class="soft-button danger-button" data-confirm="Revoke this invitation link? Existing accepted copies will stop syncing.">Revoke</button>
                                            </form>
                                        </div>
                                    @else
                                        <form method="POST" action="{{ route('calendar.events.invitation.generate', $event) }}">
                                            @csrf
                                            <button class="soft-button" type="submit">Create invitation link</button>
                                        </form>
                                    @endif
                                </div>
                            @endcan
                            @can('delete', $event)
                                <form class="calendar-delete-form" method="POST" action="{{ route('calendar.events.destroy', $event) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="soft-button danger-button" data-confirm="Delete this calendar event?">Delete event</button>
                                </form>
                            @endcan
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</section>
@endsection
