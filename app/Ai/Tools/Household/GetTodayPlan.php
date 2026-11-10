<?php

namespace App\Ai\Tools\Household;

use App\Ai\Tools\Concerns\HouseholdData;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\AdminItem;
use App\Models\Chore;
use App\Models\Event;
use App\Models\RecurringBill;
use App\Models\Todo;
use App\Plants\PlantCareService;
use App\Queries\OverdueChoresQuery;
use App\Queries\ShoppingDayQuery;
use App\Support\HomeTime;
use Carbon\CarbonImmutable;

/**
 * "What should I do today?" in one call: everything with today's date on it,
 * across chores, the calendar, to-dos, plants, bills and shopping.
 */
class GetTodayPlan extends Tool
{
    use HouseholdData;

    public function __construct(private PlantCareService $plants) {}

    public function name(): string
    {
        return 'get_today_plan';
    }

    public function label(): string
    {
        return 'Gathered today\'s plan';
    }

    public function description(): string
    {
        return 'Everything that needs doing today, from every part of the household: chores due today or overdue, today\'s calendar events, to-dos due today or overdue, plants to water or care for, bills, renewals and appointments due in the next 3 days, and whether today is the usual shopping day. Use it first for "what should I do today", "what\'s on today" or a daily summary; then use the specific tools if the person wants detail.';
    }

    public function run(array $input, ToolContext $context): array
    {
        $h = $context->household;
        $today = $context->today;
        $zone = HomeTime::zone();

        $dueToday = Chore::query()->whereBelongsTo($h)->with(['room', 'lastCompletion'])->get()
            ->filter(fn (Chore $c) => $c->lastCompletion
                && (int) CarbonImmutable::parse($c->lastCompletion->done_at)->setTimezone($zone)->startOfDay()->diffInDays($today) === $c->every_days)
            ->map(fn (Chore $c) => ['id' => $c->id, 'name' => $c->name, 'room' => $c->room->name, 'minutes' => $c->minutes])
            ->values()->all();

        $events = Event::query()->whereBelongsTo($h)
            ->where('starts_at', '<', $today->addDay()->setTimezone('UTC'))
            ->where(fn ($q) => $q->where('ends_at', '>=', $today->setTimezone('UTC'))
                ->orWhere(fn ($q) => $q->whereNull('ends_at')->where('starts_at', '>=', $today->setTimezone('UTC'))))
            ->orderBy('starts_at')->limit(20)->get()
            ->map(fn (Event $e) => [
                'title' => $e->title,
                'starts' => $e->all_day ? 'all day' : $this->homeTime($e->starts_at),
                'location' => $e->location,
            ])->all();

        $todos = Todo::query()->whereBelongsTo($h)->whereNull('done_at')
            ->whereNotNull('due_on')->where('due_on', '<=', $today->toDateString())
            ->orderBy('due_on')->limit(20)->get()
            ->map(fn (Todo $t) => ['id' => $t->id, 'title' => $t->title, 'due_on' => $t->due_on->toDateString(), 'overdue' => $t->due_on->lt($today)])
            ->all();

        $plants = $this->plants->today($h);

        $bills = RecurringBill::query()->whereBelongsTo($h)->where('active', true)
            // The next 4 days of the month, across a month's end (the 30th → 30, 31, 1, 2)
            ->whereIn('day_of_month', array_map(fn (int $i) => $today->addDays($i)->day, range(0, 3)))
            ->orderBy('day_of_month')->get()
            ->map(fn (RecurringBill $b) => ['name' => $b->name, 'amount' => $this->money($b->amount), 'day_of_month' => $b->day_of_month])
            ->all();

        $admin = AdminItem::query()->whereBelongsTo($h)->whereNull('done_at')
            ->whereNotNull('due_on')->where('due_on', '<=', $today->addDays(3)->toDateString())
            ->orderBy('due_on')->limit(10)->get()
            ->map(fn (AdminItem $a) => ['title' => $a->title, 'kind' => $a->kind->value, 'due_on' => $a->due_on->toDateString(), 'amount' => $this->money($a->amount)])
            ->all();

        $shoppingDay = (new ShoppingDayQuery($h, $today))->get()['usual'];

        return [
            'date' => $today->toDateString(),
            'weekday' => $today->englishDayOfWeek,
            'chores_due_today' => $dueToday,
            'chores_overdue' => array_slice((new OverdueChoresQuery($h, $today))->get(), 0, 10),
            'events_today' => $events,
            'todos_due' => $todos,
            'plants' => [
                'to_water' => array_map(fn ($p) => ['name' => $p['name'], 'amount_ml' => $p['amount_ml'], 'overdue_days' => $p['overdue_days'], 'skip_for_rain' => $p['postponed_for_rain']], $plants['watering']),
                'other_care' => array_map(fn ($p) => ['name' => $p['name'], 'what' => $p['title']], $plants['care']),
                'weather_warnings' => array_map(fn ($p) => ['name' => $p['name'], 'warning' => $p['title']], $plants['weather_warnings']),
            ],
            'bills_and_admin_next_3_days' => [...$bills, ...$admin],
            'shopping' => [
                'items_on_list' => $h->shoppingItems()->where('checked', false)->count(),
                'usual_shopping_day' => $shoppingDay['name'] ?? null,
                'today_is_shopping_day' => $shoppingDay !== null && $shoppingDay['next_on'] === $today->toDateString(),
            ],
        ];
    }
}
