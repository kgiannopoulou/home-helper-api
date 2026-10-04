<?php

namespace App\Planning;

use App\Enums\StockLevel;
use App\Models\AdminItem;
use App\Models\Chore;
use App\Models\Event;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\Todo;
use Carbon\CarbonImmutable;

/**
 * Next week's chores, meals and errands, placed in the household's free time.
 *
 * - Chores fall on the day they're due (last done + every N days); overdue ones on Monday.
 * - Meals use up what expires that week: "Cook with Feta", the day before it expires, from 18:00.
 * - Errands are open to-dos and life admin due by the end of the week.
 *
 * Free time is the evening on weekdays and the day at weekends, minus calendar events
 * (an all-day event, like a trip, takes the whole day). An item that doesn't fit on its
 * day moves to the next day with room, then to an earlier one; what fits nowhere is
 * listed as unplaced. Times are at home (HOME_TIMEZONE).
 *
 * The plan is worked out the same way every time from the same data, so the Apply
 * button can send only the keys of the ticked items and the server works out the rest.
 * Items already applied are recognised by their event (its emoji and title) and keep
 * their time, marked `applied`.
 */
class WeekPlanner
{
    /** Free time per ISO weekday (1 = Monday), at home */
    public const FREE = [
        1 => ['17:30', '21:30'], 2 => ['17:30', '21:30'], 3 => ['17:30', '21:30'], 4 => ['17:30', '21:30'], 5 => ['17:30', '21:30'],
        6 => ['10:00', '19:00'], 7 => ['10:00', '19:00'],
    ];

    public const MEAL_FROM = '18:00';

    public const MEAL_MINUTES = 45;

    public const ERRAND_MINUTES = 30;

    public const ADMIN_MINUTES = 15;

    /** How an applied item's calendar event starts ("🧹 Kitchen: Mop") */
    public const EMOJI = ['chore' => '🧹', 'meal' => '🍳', 'errand' => '📌'];

    private string $tz;

    /** @var array<string, list<array{0: int, 1: int}>> free gaps per day, minutes after midnight */
    private array $free = [];

    /** @var array<string, list<array{date: string, start: int}>> events already added by Apply, by title */
    private array $applied = [];

    public function __construct(private Household $household, private CarbonImmutable $weekStart)
    {
        $this->tz = (string) config('homehelper.timezone');
        $this->weekStart = CarbonImmutable::parse($weekStart->toDateString(), $this->tz)->startOfWeek();
    }

    /** The Monday after today: the week being planned. */
    public static function nextWeek(CarbonImmutable $today): CarbonImmutable
    {
        return $today->startOfWeek()->addWeek();
    }

    /**
     * @return array{week_start: string, days: list<array{date: string, free: list<array{from: string, to: string}>, busy: list<array{title: string, from: string, to: string, all_day: bool}>}>, items: list<array<string, mixed>>, unplaced: list<array<string, mixed>>}
     */
    public function plan(): array
    {
        $days = $this->days();
        $busy = $this->busy();
        foreach ($days as $date) {
            $weekday = CarbonImmutable::parse($date, $this->tz)->dayOfWeekIso;
            [$from, $to] = array_map(self::minutes(...), self::FREE[$weekday]);
            $this->free[$date] = self::subtract([[$from, $to]], $busy[$date]['blocks']);
        }
        $freeBefore = $this->free;

        $placed = [];
        $unplaced = [];
        foreach ($this->candidates() as $item) {
            $applied = $this->takeApplied($item);
            $slot = $applied ?? $this->place($item);
            if ($slot) {
                [$date, $start] = $slot;
                $placed[] = [...$item, 'date' => $date, 'start' => self::clock($start), 'end' => self::clock($start + $item['minutes']),
                    'moved' => $item['wanted'] !== null && $item['wanted'] !== $date, 'applied' => $applied !== null];
            } else {
                $unplaced[] = $item;
            }
        }
        usort($placed, fn ($a, $b) => [$a['date'], $a['start']] <=> [$b['date'], $b['start']]);

        return [
            'week_start' => $this->weekStart->toDateString(),
            'days' => array_map(fn (string $date) => [
                'date' => $date,
                'free' => array_map(fn ($g) => ['from' => self::clock($g[0]), 'to' => self::clock($g[1])], $freeBefore[$date]),
                'busy' => $busy[$date]['events'],
            ], $days),
            'items' => $placed,
            'unplaced' => $unplaced,
        ];
    }

    /**
     * @return list<string>
     */
    private function days(): array
    {
        return array_map(fn (int $i) => $this->weekStart->addDays($i)->toDateString(), range(0, 6));
    }

    private function weekEnd(): string
    {
        return $this->weekStart->addDays(6)->toDateString();
    }

    private function clamp(string $date): string
    {
        return max($this->weekStart->toDateString(), min($this->weekEnd(), $date));
    }

    /**
     * Calendar events per day, as blocks of busy minutes.
     *
     * @return array<string, array{blocks: list<array{0: int, 1: int}>, events: list<array{title: string, from: string, to: string, all_day: bool}>}>
     */
    private function busy(): array
    {
        $busy = array_fill_keys($this->days(), ['blocks' => [], 'events' => []]);
        $start = $this->weekStart->utc();
        $end = $this->weekStart->addWeek()->utc();

        $events = $this->household->events()
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->orderBy('starts_at')->get();

        foreach ($events as $e) {
            /** @var Event $e */
            $from = CarbonImmutable::parse($e->starts_at)->setTimezone($this->tz);
            $to = CarbonImmutable::parse($e->ends_at)->setTimezone($this->tz);
            // Added by Apply earlier: the item keeps that time, it isn't busy time to plan around
            if (! $e->all_day && preg_match('/^('.implode('|', self::EMOJI).') /u', $e->title)) {
                $this->applied[$e->title][] = ['date' => $from->toDateString(), 'start' => $from->hour * 60 + $from->minute];

                continue;
            }
            foreach ($this->days() as $date) {
                $dayStart = CarbonImmutable::parse($date, $this->tz);
                $dayEnd = $dayStart->addDay();
                if ($from >= $dayEnd || $to <= $dayStart) {
                    continue;
                }
                $a = $e->all_day ? 0 : (int) $dayStart->diffInMinutes(max($from, $dayStart));
                $b = $e->all_day ? 1440 : (int) $dayStart->diffInMinutes(min($to, $dayEnd));
                $busy[$date]['blocks'][] = [$a, $b];
                $busy[$date]['events'][] = ['title' => $e->title, 'from' => self::clock($a), 'to' => self::clock($b), 'all_day' => (bool) $e->all_day];
            }
        }

        return $busy;
    }

    /**
     * Everything to place, the ones bound to a day first.
     *
     * @return list<array{key: string, kind: string, title: string, detail: string|null, minutes: int, wanted: string|null}>
     */
    private function candidates(): array
    {
        $weekStart = $this->weekStart->toDateString();
        $weekEnd = $this->weekEnd();
        $items = [];

        // Chores, on each day they come due
        $chores = $this->household->chores()->with(['room', 'assignee', 'lastCompletion'])->orderBy('name')->get();
        foreach ($chores as $chore) {
            /** @var Chore $chore */
            $last = $chore->lastCompletion?->done_at;
            $next = $last
                ? CarbonImmutable::parse($last)->setTimezone($this->tz)->startOfDay()->addDays($chore->every_days)->toDateString()
                : $weekStart;
            $day = max($next, $weekStart);
            while ($day <= $weekEnd) {
                $items[] = [
                    'key' => "chore:{$chore->id}:{$day}",
                    'kind' => 'chore',
                    'title' => "{$chore->room->name}: {$chore->name}",
                    'detail' => collect(["every {$chore->every_days} ".($chore->every_days === 1 ? 'day' : 'days'), $chore->assignee?->name, $next < $weekStart && $day === $weekStart ? 'overdue' : null])->filter()->implode(' · '),
                    'minutes' => $chore->minutes,
                    'wanted' => $day,
                ];
                $day = CarbonImmutable::parse($day)->addDays($chore->every_days)->toDateString();
            }
        }

        // Meals that use up what expires this week, the evening before it does
        $expiring = $this->household->inventoryItems()
            ->whereBetween('expires_on', [$weekStart, $weekEnd])
            ->where('level', '!=', StockLevel::Empty)
            ->orderBy('expires_on')->orderBy('name')->get()
            ->groupBy(fn (InventoryItem $i) => max($weekStart, $i->expires_on->copy()->subDay()->toDateString()));
        foreach ($expiring as $day => $group) {
            $names = $group->pluck('name')->all();
            $items[] = [
                'key' => "meal:{$day}",
                'kind' => 'meal',
                'title' => 'Cook with '.self::andList($names),
                'detail' => 'expires '.$group->map(fn (InventoryItem $i) => $i->expires_on->format('D j M'))->unique()->implode(', '),
                'minutes' => self::MEAL_MINUTES,
                'wanted' => (string) $day,
            ];
        }

        // Errands: open to-dos and life admin due by the end of the week
        $todos = $this->household->todos()->whereNull('done_at')
            ->where(fn ($q) => $q->whereNull('due_on')->orWhere('due_on', '<=', $weekEnd))
            ->orderByRaw('due_on IS NULL, due_on')->orderBy('title')->get();
        foreach ($todos as $todo) {
            /** @var Todo $todo */
            $items[] = [
                'key' => "todo:{$todo->id}",
                'kind' => 'errand',
                'title' => $todo->title,
                'detail' => $todo->due_on ? 'due '.$todo->due_on->format('D j M') : 'any day',
                'minutes' => $todo->minutes ?? self::ERRAND_MINUTES,
                'wanted' => $todo->due_on ? $this->clamp($todo->due_on->toDateString()) : null,
            ];
        }
        $admin = $this->household->adminItems()->whereNull('done_at')->where('due_on', '<=', $weekEnd)->orderBy('due_on')->get();
        foreach ($admin as $a) {
            /** @var AdminItem $a */
            $items[] = [
                'key' => "admin:{$a->id}",
                'kind' => 'errand',
                'title' => $a->title,
                'detail' => 'due '.$a->due_on->format('D j M'),
                'minutes' => self::ADMIN_MINUTES,
                'wanted' => $this->clamp($a->due_on->toDateString()),
            ];
        }

        // Day-bound first (meals before the rest, longer before shorter), then "any day"
        $order = ['meal' => 0, 'errand' => 1, 'chore' => 2];
        usort($items, fn ($a, $b) => [$a['wanted'] === null, $a['wanted'], $order[$a['kind']], -$a['minutes'], $a['key']]
            <=> [$b['wanted'] === null, $b['wanted'], $order[$b['kind']], -$b['minutes'], $b['key']]);

        return $items;
    }

    /**
     * An item already in the calendar keeps its time: the event on its day, or the
     * first one left with its title (it may have been moved to another day).
     *
     * @param  array{kind: string, title: string, minutes: int, wanted: string|null}  $item
     * @return array{0: string, 1: int}|null
     */
    private function takeApplied(array $item): ?array
    {
        $title = self::EMOJI[$item['kind']].' '.$item['title'];
        $events = $this->applied[$title] ?? [];
        if (! $events) {
            return null;
        }
        $i = array_search($item['wanted'], array_column($events, 'date'), true);
        $i = $i === false ? 0 : $i;
        ['date' => $date, 'start' => $start] = $events[$i];
        array_splice($this->applied[$title], $i, 1);
        if (isset($this->free[$date])) {
            $this->free[$date] = self::subtract($this->free[$date], [[$start, $start + $item['minutes']]]);
        }

        return [$date, $start];
    }

    /**
     * Takes the time for an item: its day first, then later days, then earlier ones.
     *
     * @param  array{kind: string, minutes: int, wanted: string|null}  $item
     * @return array{0: string, 1: int}|null the day and the start, in minutes after midnight
     */
    private function place(array $item): ?array
    {
        $days = $this->days();
        $i = $item['wanted'] === null ? 0 : (int) array_search($item['wanted'], $days, true);
        $order = [...array_slice($days, $i), ...array_reverse(array_slice($days, 0, $i))];
        $earliest = $item['kind'] === 'meal' ? self::minutes(self::MEAL_FROM) : 0;

        foreach ($order as $date) {
            foreach ($this->free[$date] as [$from, $to]) {
                $start = max($from, $earliest);
                if ($start + $item['minutes'] > $to) {
                    continue;
                }
                $this->free[$date] = self::subtract($this->free[$date], [[$start, $start + $item['minutes']]]);

                return [$date, $start];
            }
        }

        return null;
    }

    /**
     * Gaps minus blocks.
     *
     * @param  list<array{0: int, 1: int}>  $gaps
     * @param  list<array{0: int, 1: int}>  $blocks
     * @return list<array{0: int, 1: int}>
     */
    private static function subtract(array $gaps, array $blocks): array
    {
        foreach ($blocks as [$b0, $b1]) {
            $next = [];
            foreach ($gaps as [$g0, $g1]) {
                if ($b1 <= $g0 || $b0 >= $g1) {
                    $next[] = [$g0, $g1];

                    continue;
                }
                if ($b0 > $g0) {
                    $next[] = [$g0, $b0];
                }
                if ($b1 < $g1) {
                    $next[] = [$b1, $g1];
                }
            }
            $gaps = $next;
        }

        return $gaps;
    }

    private static function minutes(string $clock): int
    {
        [$h, $m] = array_map('intval', explode(':', $clock));

        return $h * 60 + $m;
    }

    private static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @param  list<string>  $names
     */
    private static function andList(array $names): string
    {
        $last = array_pop($names);

        return $names ? implode(', ', $names)." and {$last}" : (string) $last;
    }
}
