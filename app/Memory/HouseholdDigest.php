<?php

namespace App\Memory;

use App\Models\Household;
use App\Models\HouseholdSummary;
use App\Queries\OverdueChoresQuery;
use App\Queries\TimelineQuery;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled household summaries (docs/memory.md#household-summaries): how a
 * week or a month went, in numbers and in a paragraph, saved in
 * household_summaries and so in long-term memory. They let the assistant
 * answer "how did October go?" or "did we spend more in the summer?" from a
 * few passages instead of thousands of rows.
 *
 * Written by the numbers, not by a model: the same data gives the same text,
 * and nothing is invented.
 */
class HouseholdDigest
{
    /** The week (Monday to Sunday) or month containing $day */
    public function write(Household $household, string $period, CarbonImmutable $day): HouseholdSummary
    {
        [$from, $to] = self::bounds($period, $day);
        $stats = $this->stats($household, $from, $to, $period);

        return $household->summaries()->updateOrCreate(
            ['period' => $period, 'starts_on' => $from->toDateString()],
            ['ends_on' => $to->toDateString(), 'stats' => $stats, 'text' => $this->text($household, $period, $from, $to, $stats)],
        );
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function bounds(string $period, CarbonImmutable $day): array
    {
        $day = $day->startOfDay();

        return $period === 'month'
            ? [$day->startOfMonth(), $day->endOfMonth()->startOfDay()]
            : [$day->startOfWeek(CarbonImmutable::MONDAY), $day->startOfWeek(CarbonImmutable::MONDAY)->addDays(6)];
    }

    /** @return array<string, mixed> */
    public function stats(Household $household, CarbonImmutable $from, CarbonImmutable $to, string $period): array
    {
        $timeline = (new TimelineQuery($household, $to, null, $from, $to))->counts();
        $count = fn (string $kind) => $timeline[$kind]['count'] ?? 0;

        $spending = fn (CarbonImmutable $a, CarbonImmutable $b) => round((float) $household->expenses()
            ->whereBetween('date', [$a->toDateString(), $b->toDateString()])->sum('amount'), 2);
        [$prevFrom, $prevTo] = self::bounds($period, $from->subDay());

        $categories = $household->expenses()->getQuery()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('category, round(sum(amount), 2) as total')->groupBy('category')->orderByDesc('total')->limit(3)
            ->toBase()->get()->map(fn (object $r) => ['category' => (string) $r->category, 'total' => (float) $r->total])->all();

        $choresBy = DB::table('chore_completions as cc')->leftJoin('users as u', 'u.id', '=', 'cc.user_id')
            ->where('cc.household_id', $household->id)->whereNull('cc.deleted_at')
            ->whereBetween('cc.done_at', self::utcBounds($from, $to))
            ->groupBy('u.name')->selectRaw("coalesce(u.name, 'someone') as name, count(*) as n")->orderByDesc('n')
            ->pluck('n', 'name')->map(fn ($n) => (int) $n)->all();

        $events = DB::table('events')->where('household_id', $household->id)->whereNull('deleted_at')
            ->whereBetween('starts_at', self::utcBounds($from, $to))->orderBy('starts_at')->limit(5)->pluck('title')->all();

        $notes = DB::table('notes')->where('household_id', $household->id)->where('private', false)
            ->whereBetween('created_at', self::utcBounds($from, $to))->orderBy('created_at')->limit(5)->pluck('title')->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'spent' => $spending($from, $to),
            'spent_previous' => $spending($prevFrom, $prevTo),
            'top_categories' => $categories,
            'chores_done' => $count('chore'),
            'chores_by' => $choresBy,
            'chores_overdue_at_end' => count((new OverdueChoresQuery($household, $to))->get()),
            'todos_done' => $count('todo'),
            'plant_care' => $count('plant_care'),
            'shopping_trips' => $count('shopping_trip'),
            'shopping_spent' => $timeline['shopping_trip']['amount'] ?? 0.0,
            'events' => $events,
            'admin_done' => $count('admin'),
            'notes_added' => $notes,
            'facts_added' => $count('fact'),
        ];
    }

    /** @param array<string, mixed> $s */
    public function text(Household $household, string $period, CarbonImmutable $from, CarbonImmutable $to, array $s): string
    {
        $money = fn (float $x) => Money::exact($x, $household->currency);
        $label = $period === 'month' ? $from->format('F Y') : 'The week of '.$from->format('D j M').' to '.$to->format('D j M Y');

        $spent = "{$label}: spent ".$money($s['spent']);
        if ($s['top_categories']) {
            $spent .= ' ('.implode(', ', array_map(fn ($c) => str_replace('_', ' ', $c['category']).' '.$money($c['total']), $s['top_categories'])).')';
        }
        if ($s['spent_previous'] > 0) {
            $change = (int) round(($s['spent'] - $s['spent_previous']) / $s['spent_previous'] * 100);
            $spent .= match (true) {
                $change > 0 => ", {$change}% more than the {$period} before",
                $change < 0 => ', '.abs($change)."% less than the {$period} before",
                default => ", the same as the {$period} before",
            }.' ('.$money($s['spent_previous']).')';
        }
        $parts = [$spent.'.'];

        $chores = self::count($s['chores_done'], 'chore').' done';
        if ($s['chores_by']) {
            $chores .= ' ('.implode(', ', array_map(fn ($name, $n) => "{$name} {$n}", array_keys($s['chores_by']), $s['chores_by'])).')';
        }
        $parts[] = $chores.", {$s['chores_overdue_at_end']} overdue at the end; ".self::count($s['todos_done'], 'to-do').' done.';
        $parts[] = 'Plants cared for '.self::count($s['plant_care'], 'time').'. '.self::count($s['shopping_trips'], 'shopping trip').' ('.$money((float) $s['shopping_spent']).').';
        if ($s['events']) {
            $parts[] = 'On the calendar: '.implode(', ', $s['events']).'.';
        }
        if ($s['admin_done']) {
            $parts[] = "{$s['admin_done']} bills, renewals or appointments dealt with.";
        }
        if ($s['notes_added']) {
            $parts[] = 'Notes added: '.implode(', ', $s['notes_added']).'.';
        }

        return implode(' ', $parts);
    }

    /** "1 chore", "3 chores" */
    private static function count(int $n, string $thing): string
    {
        return $n.' '.($n === 1 ? $thing : $thing.'s');
    }

    /** @return array{0: string, 1: string} a home-day range as UTC timestamps */
    private static function utcBounds(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $zone = config('homehelper.timezone');

        return [
            CarbonImmutable::parse($from->toDateString(), $zone)->utc()->format('Y-m-d H:i:s.v'),
            CarbonImmutable::parse($to->toDateString(), $zone)->endOfDay()->utc()->format('Y-m-d H:i:s.v'),
        ];
    }
}
