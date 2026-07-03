@php
    $snoozeOptions = [
        ['label' => 'Later today', 'time' => now()->addHours(3)],
        ['label' => 'Tomorrow', 'time' => now()->addDay()->setTime(9, 0)],
        ['label' => 'Next week', 'time' => now()->addWeek()->setTime(9, 0)],
    ];
@endphp

<div class="snooze-control">
    <header>
        <x-icon name="clock" />
        <span>
            <strong>Snooze until</strong>
            <small>
                @if($entry->snoozed_until && $entry->snoozed_until->isFuture())
                    Current: {{ $entry->snoozed_until->format('M j, H:i') }}
                @else
                    Bring this back later
                @endif
            </small>
        </span>
    </header>
    <form method="POST" action="{{ $action }}">
        @csrf
        <input type="hidden" name="action" value="snooze">
        <div class="snooze-presets">
            @foreach($snoozeOptions as $option)
                <button type="submit" name="snoozed_until" value="{{ $option['time']->format('Y-m-d H:i:s') }}">
                    <strong>{{ $option['label'] }}</strong>
                    <small>{{ $option['time']->format('M j, H:i') }}</small>
                </button>
            @endforeach
        </div>
        <label class="snooze-custom">
            <span>Custom time</span>
            <input type="datetime-local" name="snoozed_until" value="{{ now()->addDay()->format('Y-m-d\TH:i') }}">
        </label>
        <button class="soft-button small" type="submit">Set custom snooze</button>
    </form>
    @if($entry->snoozed_until)
        <form class="snooze-unsnooze" method="POST" action="{{ $action }}">
            @csrf
            <input type="hidden" name="action" value="unsnooze">
            <button class="soft-button small" type="submit">Unsnooze</button>
        </form>
    @endif
</div>
