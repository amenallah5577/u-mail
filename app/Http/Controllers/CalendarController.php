<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $filter = in_array($request->query('filter'), [CalendarEvent::SCOPE_SHARED, CalendarEvent::SCOPE_PERSONAL], true)
            ? $request->query('filter')
            : 'all';
        $month = $this->requestedMonth($request);
        $monthStart = $month->startOfMonth();
        $monthEnd = $month->endOfMonth();
        $gridStart = $monthStart->startOfWeek(CarbonInterface::MONDAY);
        $gridEnd = $monthEnd->endOfWeek(CarbonInterface::SUNDAY);

        $events = CalendarEvent::visibleTo($request->user())
            ->when($filter !== 'all', fn ($query) => $query->where('scope', $filter))
            ->where('starts_at', '<=', $gridEnd->endOfDay())
            ->where('ends_at', '>=', $gridStart->startOfDay())
            ->with(['creator', 'owner'])
            ->orderBy('starts_at')
            ->orderBy('title')
            ->get();

        return view('calendar.index', [
            'agendaEvents' => $events
                ->filter(fn (CalendarEvent $event) => $event->starts_at->lte($monthEnd->endOfDay()) && $event->ends_at->gte($monthStart->startOfDay()))
                ->values(),
            'eventsByDate' => $this->eventsByDate($events, $gridStart, $gridEnd),
            'filter' => $filter,
            'gridEnd' => $gridEnd,
            'gridStart' => $gridStart,
            'month' => $month,
            'nextMonth' => $month->addMonth()->format('Y-m'),
            'previousMonth' => $month->subMonth()->format('Y-m'),
            'todayMonth' => CarbonImmutable::now()->startOfMonth()->format('Y-m'),
            'weeks' => $this->weeks($gridStart, $gridEnd, $month),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->eventData($request, true);
        Gate::authorize('create', [CalendarEvent::class, $data['scope']]);

        CalendarEvent::create([
            ...$data,
            'created_by' => $request->user()->id,
            'owner_id' => $data['scope'] === CalendarEvent::SCOPE_PERSONAL ? $request->user()->id : null,
        ]);

        return redirect()
            ->route('calendar.index', ['month' => $data['starts_at']->format('Y-m'), 'filter' => $data['scope']])
            ->with('status', 'Calendar event created.');
    }

    public function update(Request $request, CalendarEvent $calendarEvent)
    {
        Gate::authorize('update', $calendarEvent);
        $data = $this->eventData($request, false);
        $calendarEvent->update($data);
        if ($calendarEvent->canHaveInvitation() && $calendarEvent->hasActiveInvitation()) {
            $calendarEvent->syncActiveAcceptedCopies($data);
        }

        return redirect()
            ->route('calendar.index', ['month' => $calendarEvent->starts_at->format('Y-m'), 'filter' => $calendarEvent->scope])
            ->with('status', 'Calendar event updated.');
    }

    public function destroy(Request $request, CalendarEvent $calendarEvent)
    {
        Gate::authorize('delete', $calendarEvent);
        $month = $calendarEvent->starts_at->format('Y-m');
        $filter = $calendarEvent->scope;
        if ($calendarEvent->canHaveInvitation()) {
            $calendarEvent->acceptedCopies()->delete();
        }
        $calendarEvent->delete();

        return redirect()
            ->route('calendar.index', ['month' => $month, 'filter' => $filter])
            ->with('status', 'Calendar event deleted.');
    }

    private function eventData(Request $request, bool $creating): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:160'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        if ($creating) {
            $rules['scope'] = ['required', Rule::in([CalendarEvent::SCOPE_PERSONAL, CalendarEvent::SCOPE_SHARED])];
        }

        $data = $request->validate($rules);
        $data['starts_at'] = CarbonImmutable::parse($data['starts_at']);
        $data['ends_at'] = CarbonImmutable::parse($data['ends_at']);

        return $data;
    }

    private function requestedMonth(Request $request): CarbonImmutable
    {
        $value = trim((string) $request->query('month', ''));

        if ($value !== '') {
            try {
                return CarbonImmutable::createFromFormat('!Y-m-d', $value.'-01')->startOfMonth();
            } catch (\Throwable) {
                //
            }
        }

        return CarbonImmutable::now()->startOfMonth();
    }

    private function weeks(CarbonImmutable $gridStart, CarbonImmutable $gridEnd, CarbonImmutable $month): array
    {
        $weeks = [];
        $week = [];

        for ($day = $gridStart; $day->lte($gridEnd); $day = $day->addDay()) {
            $week[] = [
                'date' => $day,
                'isCurrentMonth' => $day->isSameMonth($month),
                'isToday' => $day->isToday(),
            ];

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }

        return $weeks;
    }

    private function eventsByDate($events, CarbonImmutable $gridStart, CarbonImmutable $gridEnd): array
    {
        $map = [];

        foreach ($events as $event) {
            $cursor = $event->starts_at->toImmutable()->startOfDay()->max($gridStart->startOfDay());
            $last = $event->ends_at->toImmutable()->startOfDay()->min($gridEnd->startOfDay());

            for ($day = $cursor; $day->lte($last); $day = $day->addDay()) {
                $map[$day->toDateString()][] = $event;
            }
        }

        return $map;
    }
}
