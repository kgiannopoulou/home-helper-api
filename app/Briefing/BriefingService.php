<?php

namespace App\Briefing;

use App\Ai\Tools\Finance\GetSpendingSummary;
use App\Ai\Tools\Finance\GetUpcomingBills;
use App\Ai\Tools\Household\GetTodayPlan;
use App\Ai\Tools\Plants\GetWeatherForecast;
use App\Ai\Tools\Plants\ListHomeMaintenance;
use App\Ai\Tools\Shopping\GetKitchenInventory;
use App\Ai\Tools\Shopping\GetShoppingList;
use App\Ai\Tools\Tasks\ListUpcomingEvents;
use App\Ai\Tools\ToolContext;
use App\Models\Briefing;
use App\Models\Chore;
use App\Models\Household;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The daily briefing (docs/briefings.md): one member's morning, from every
 * part of the household.
 *
 *   weather · calendar · tasks · shopping · money · bills · plants · upkeep · recommendations
 *
 * It reads through the assistant's own read tools, so the briefing, the
 * assistant and the dashboard agree on every number. A section whose source
 * fails (the weather service is down) is left out with a note, never guessed.
 */
class BriefingService
{
    public function __construct(
        private GetTodayPlan $todayPlan,
        private GetWeatherForecast $weather,
        private ListUpcomingEvents $events,
        private GetShoppingList $shopping,
        private GetKitchenInventory $kitchen,
        private GetSpendingSummary $spending,
        private GetUpcomingBills $bills,
        private ListHomeMaintenance $maintenance,
        private Recommender $recommender,
    ) {}

    /** @return array<string, mixed> */
    public function build(Household $household, User $user, CarbonImmutable $today): array
    {
        $ctx = new ToolContext($household, $user, $today);
        $unavailable = [];
        $read = function (string $section, callable $read) use (&$unavailable) {
            try {
                return $read();
            } catch (Throwable $e) {
                report($e);
                $unavailable[] = $section;

                return null;
            }
        };

        $plan = $read('tasks', fn () => $this->todayPlan->run([], $ctx));
        $weather = $read('weather', fn () => $this->weather->run([], $ctx));
        $events = $read('calendar', fn () => $this->events->run(['days' => 2], $ctx));
        $shopping = $read('shopping', fn () => $this->shopping->run([], $ctx));
        $kitchen = $read('kitchen', fn () => $this->kitchen->run(['expiring_within_days' => 2], $ctx));
        $money = $read('money', fn () => $this->spending->run([], $ctx));
        $bills = $read('bills', fn () => $this->bills->run(['days' => 7], $ctx));
        $upkeep = $read('maintenance', fn () => $this->maintenance->run(['days' => 14], $ctx));

        $mine = Chore::query()->whereBelongsTo($household)->where('assignee_id', $user->id)->pluck('id')->all();
        $tag = fn (array $chores) => array_map(fn (array $c) => $c + ['yours' => in_array($c['id'], $mine, true)], $chores);
        $tomorrow = $today->addDay()->toDateString();

        $briefing = [
            'date' => $today->toDateString(),
            'weekday' => $today->englishDayOfWeek,
            'for' => $user->name,
            'currency' => $household->currency,
            'weather' => $weather && $weather['available'] ? $weather['next_24_hours'] : null,
            'weather_note' => $weather && ! $weather['available'] ? $weather['reason'] : null,
            'calendar' => [
                'today' => $plan['events_today'] ?? [],
                'tomorrow' => array_values(array_filter($events['events'] ?? [], fn (array $e) => str_starts_with($e['starts'], $tomorrow))),
            ],
            'tasks' => [
                'todos' => $plan['todos_due'] ?? [],
                'chores_due' => $tag($plan['chores_due_today'] ?? []),
                'chores_overdue' => $tag(array_slice($plan['chores_overdue'] ?? [], 0, 5)),
            ],
            'shopping' => $shopping ? [
                'count' => $shopping['count'],
                'estimated_total' => $shopping['estimated_total'],
                'usual_day' => $shopping['usual_shopping_day'],
                'today_is_shopping_day' => $plan['shopping']['today_is_shopping_day'] ?? false,
                'running_out' => $shopping['suggested_not_on_list'],
            ] : null,
            'kitchen' => $kitchen ? ['expiring' => $kitchen['expiring'], 'low_or_empty' => array_slice($kitchen['low_or_empty'], 0, 8)] : null,
            'money' => $money ? [
                'spent_yesterday' => round((float) $household->expenses()->whereDate('date', $today->subDay()->toDateString())->sum('amount'), 2),
                'month_to_date' => $money['total'],
                'budget' => $money['budget'],
                'forecast' => $money['forecast']['forecast'] ?? null,
                'over_budget' => $money['forecast']['over_budget'] ?? null,
                'top_categories' => array_slice($money['categories'], 0, 3),
            ] : null,
            'bills' => $bills ? [...array_map(fn ($b) => $b + ['kind' => 'recurring'], $bills['recurring']), ...$bills['one_off_and_renewals']] : [],
            'plants' => $plan['plants'] ?? null,
            'maintenance' => $upkeep ? ['due' => $upkeep['admin'], 'supplies_low_or_out' => $upkeep['supplies_low_or_out']] : null,
        ];
        $briefing['recommendations'] = $this->recommender->recommend($household, $user, $today, $briefing);
        $briefing['headline'] = self::headline($briefing);
        $briefing['unavailable'] = $unavailable;

        return $briefing;
    }

    /** Builds, stores and returns today's briefing; built again if it exists (the data moved on) */
    public function store(Household $household, User $user, CarbonImmutable $today): Briefing
    {
        $content = $this->build($household, $user, $today);

        $briefing = Briefing::query()->whereBelongsTo($household)->where('user_id', $user->id)->whereDate('date', $today->toDateString())->first()
            ?? new Briefing(['user_id' => $user->id, 'date' => $today->toDateString()]);
        $briefing->household_id = $household->id;
        $briefing->fill(['content' => $content, 'text' => self::text($content)])->save();

        return $briefing;
    }

    /**
     * "3 things due · 2 plants to water · Rent on Mon" — the push notification
     *
     * @param  array<string, mixed>  $b
     */
    public static function headline(array $b): string
    {
        $parts = [];
        $tasks = count($b['tasks']['todos']) + count($b['tasks']['chores_due']) + count($b['tasks']['chores_overdue']);
        $parts[] = match ($tasks) {
            0 => 'nothing due',
            1 => '1 thing due',
            default => "{$tasks} things due",
        };
        if ($b['calendar']['today']) {
            $first = $b['calendar']['today'][0];
            $parts[] = $first['title'].($first['starts'] !== 'all day' ? ' at '.substr($first['starts'], 11) : '');
        }
        $water = count(array_filter($b['plants']['to_water'] ?? [], fn ($p) => ! $p['skip_for_rain']));
        if ($water) {
            $parts[] = $water === 1 ? '1 plant to water' : "{$water} plants to water";
        }
        if ($b['bills']) {
            $bill = $b['bills'][0];
            $parts[] = ($bill['name'] ?? $bill['title']).' due '.CarbonImmutable::parse($bill['due_on'])->format('D j');
        }
        if ($b['weather']) {
            $parts[] = round($b['weather']['minimum_temperature_c']).'–'.round($b['weather']['maximum_temperature_c']).' °C'
                .($b['weather']['precipitation_mm'] >= 1 ? ', rain' : '');
        }

        return ucfirst(implode(' · ', $parts));
    }

    /**
     * The whole briefing as plain text, for email, the API and the assistant.
     *
     * @param  array<string, mixed>  $b
     */
    public static function text(array $b): string
    {
        $money = fn (?float $x) => $x === null ? '—' : Money::exact($x, $b['currency']);
        $date = CarbonImmutable::parse($b['date']);
        $out = ["Good morning, {$b['for']}. {$date->format('l j F')}.", $b['headline'].'.'];
        $section = function (string $title, array $lines) use (&$out) {
            if ($lines) {
                $out[] = '';
                $out[] = $title;
                foreach ($lines as $l) {
                    $out[] = "- {$l}";
                }
            }
        };

        if ($w = $b['weather']) {
            $section('Weather', [round($w['minimum_temperature_c']).'–'.round($w['maximum_temperature_c'])." °C, {$w['precipitation_mm']} mm of rain, gusts up to {$w['max_wind_gust_kmh']} km/h"]);
        }
        $section('Calendar', [
            ...array_map(fn ($e) => "{$e['starts']}: {$e['title']}".($e['location'] ? " ({$e['location']})" : ''), $b['calendar']['today']),
            ...array_map(fn ($e) => "Tomorrow {$e['starts']}: {$e['title']}", $b['calendar']['tomorrow']),
        ]);
        $section('Tasks', [
            ...array_map(fn ($t) => "To-do: {$t['title']}".($t['overdue'] ? ' (overdue)' : ''), $b['tasks']['todos']),
            ...array_map(fn ($c) => "Chore: {$c['room']}: {$c['name']}".($c['yours'] ? ' (yours)' : ''), $b['tasks']['chores_due']),
            ...array_map(fn ($c) => "Overdue: {$c['room']}: {$c['name']}, {$c['days_overdue']} days late".($c['yours'] ? ' (yours)' : ''), $b['tasks']['chores_overdue']),
        ]);
        if ($s = $b['shopping']) {
            $section('Shopping', array_filter([
                $s['count'].($s['count'] === 1 ? ' item' : ' items').' on the list'.($s['estimated_total'] ? ', about '.$money($s['estimated_total']) : ''),
                $s['today_is_shopping_day'] ? 'Today is your usual shopping day' : ($s['usual_day'] ? "Usual shopping day: {$s['usual_day']['day']}" : null),
                $s['running_out'] ? 'Running out, not on the list: '.implode(', ', array_column($s['running_out'], 'name')) : null,
                ($b['kitchen']['expiring'] ?? []) ? 'Use soon: '.implode(', ', array_column($b['kitchen']['expiring'], 'name')) : null,
            ]));
        }
        if ($m = $b['money']) {
            $section('Money', array_filter([
                'Spent yesterday: '.$money($m['spent_yesterday']),
                'This month so far: '.$money($m['month_to_date']).($m['budget'] ? ' of '.$money($m['budget']) : ''),
                $m['forecast'] !== null ? 'At this pace: '.$money($m['forecast']).($m['over_budget'] > 0 ? ', '.$money($m['over_budget']).' over budget' : '') : null,
            ]));
        }
        $section('Bills this week', array_map(fn ($x) => ($x['name'] ?? $x['title']).' '.$money($x['amount']).' on '.CarbonImmutable::parse($x['due_on'])->format('D j M'), $b['bills']));
        if ($p = $b['plants']) {
            $section('Plants', [
                ...array_map(fn ($x) => $x['skip_for_rain'] ? "{$x['name']}: skip watering, rain is coming" : "Water {$x['name']}".($x['amount_ml'] ? " ({$x['amount_ml']} ml)" : ''), $p['to_water']),
                ...array_map(fn ($x) => "{$x['name']}: {$x['what']}", $p['other_care']),
                ...array_map(fn ($x) => "{$x['name']}: {$x['warning']}", $p['weather_warnings']),
            ]);
        }
        if ($u = $b['maintenance']) {
            $section('Home upkeep', [
                ...array_map(fn ($a) => "{$a['title']} ({$a['kind']}) due ".CarbonImmutable::parse($a['due_on'])->format('D j M').($a['overdue'] ? ', overdue' : ''), $u['due']),
                ...array_map(fn ($s) => "{$s['name']} is {$s['level']}", $u['supplies_low_or_out']),
            ]);
        }
        $section('Suggestions', array_map(fn ($r) => $r['title'].' ('.$r['reason'].')', $b['recommendations']));
        if ($b['unavailable']) {
            $out[] = '';
            $out[] = 'Not available right now: '.implode(', ', $b['unavailable']).'.';
        }

        return implode("\n", $out);
    }
}
