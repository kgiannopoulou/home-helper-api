<?php

use App\Enums\HouseholdRole;
use App\Enums\StockLevel;
use App\Models\AdminItem;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Event;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\Room;
use App\Models\Todo;
use App\Models\User;
use App\Planning\WeekPlanner;
use Carbon\CarbonImmutable;

/*
 * Planning the week of Monday 12 October 2026, from Thursday the 8th, at home in Athens (UTC+3).
 */
beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-10-08 12:00', new DateTimeZone('Europe/Athens')));
    $this->home = Household::factory()->create();
    $this->me = User::factory()->create();
    $this->home->addMember($this->me, HouseholdRole::Owner);
    $kitchen = Room::factory()->for($this->home)->create(['name' => 'Kitchen']);

    $chore = function (string $name, int $every, int $minutes, ?string $lastDone) use ($kitchen) {
        $c = Chore::factory()->for($this->home)->for($kitchen)->create(['name' => $name, 'every_days' => $every, 'minutes' => $minutes, 'assignee_id' => null]);
        if ($lastDone) {
            ChoreCompletion::factory()->for($this->home)->for($c)->create(['done_at' => $lastDone, 'minutes' => $minutes]);
        }

        return $c;
    };
    $this->mop = $chore('Mop', 7, 15, '2026-10-07 07:00:00');          // due Wednesday the 14th
    $this->oven = $chore('Clean the oven', 14, 40, '2026-09-20 07:00:00'); // overdue since the 4th
    $chore('Defrost freezer', 90, 30, null);                            // never done
    $chore('Paint the hall', 30, 600, null);                            // 10 hours: fits nowhere

    // Football on Monday evening, and away all of Saturday
    Event::factory()->for($this->home)->create(['title' => 'Football', 'starts_at' => '2026-10-12 14:00:00', 'ends_at' => '2026-10-12 16:00:00', 'all_day' => false]);
    Event::factory()->for($this->home)->create(['title' => 'Nafplio', 'starts_at' => '2026-10-16 21:00:00', 'ends_at' => '2026-10-17 20:59:00', 'all_day' => true]);

    InventoryItem::factory()->for($this->home)->create(['name' => 'Feta', 'level' => StockLevel::Full, 'expires_on' => '2026-10-16']);
    $this->plumber = Todo::factory()->for($this->home)->create(['title' => 'Call the plumber', 'due_on' => '2026-10-13', 'minutes' => 10, 'done_at' => null]);
    $this->shelf = Todo::factory()->for($this->home)->create(['title' => 'Fix the shelf', 'due_on' => null, 'minutes' => 60, 'done_at' => null]);
    AdminItem::factory()->for($this->home)->create(['title' => 'Car insurance', 'due_on' => '2026-10-16', 'done_at' => null]);
    AdminItem::factory()->for($this->home)->create(['title' => 'Passport', 'due_on' => '2026-12-01', 'done_at' => null]);
});

function weekPlan(): array
{
    return (new WeekPlanner(test()->home, CarbonImmutable::parse('2026-10-12')))->plan();
}

/** "2026-10-12 19:00 Clean the oven" for each placed item */
function placed(array $plan): array
{
    return array_map(fn ($i) => "{$i['date']} {$i['start']} {$i['title']}", $plan['items']);
}

describe('the plan', function () {
    test('places chores, a meal and errands in free time around the calendar', function () {
        $plan = weekPlan();

        expect(placed($plan))->toContain(
            // Monday is busy until 19:00 with football: the overdue oven and the never-done freezer come after it
            '2026-10-12 19:00 Kitchen: Clean the oven',
            '2026-10-12 19:40 Kitchen: Defrost freezer',
            // "Any day" errands take what's left, from Monday
            '2026-10-12 20:10 Fix the shelf',
            '2026-10-13 17:30 Call the plumber',
            '2026-10-14 17:30 Kitchen: Mop',
            // Feta expires on Friday: cook with it on Thursday evening, from 18:00
            '2026-10-15 18:00 Cook with Feta',
            '2026-10-16 17:30 Car insurance',
        )->and(placed($plan))->not->toContain('2026-12-01 17:30 Passport');

        $oven = collect($plan['items'])->firstWhere('title', 'Kitchen: Clean the oven');
        expect($oven)->toMatchArray(['kind' => 'chore', 'minutes' => 40, 'end' => '19:40', 'moved' => false])
            ->and($oven['detail'])->toContain('overdue');
    });

    test('nothing lands on a day taken by an all-day event, and what fits nowhere is listed', function () {
        $plan = weekPlan();

        expect(collect($plan['items'])->where('date', '2026-10-17'))->toBeEmpty()
            ->and($plan['days'][5])->toMatchArray(['date' => '2026-10-17', 'free' => []])
            ->and($plan['days'][0]['busy'])->toBe([['title' => 'Football', 'from' => '17:00', 'to' => '19:00', 'all_day' => false]])
            ->and(array_column($plan['unplaced'], 'title'))->toBe(['Kitchen: Paint the hall']);
    });

    test('a daily chore comes every day, and moves when its day is full', function () {
        $kitchen = Room::where('name', 'Kitchen')->sole();
        Chore::factory()->for($this->home)->for($kitchen)->create(['name' => 'Wipe counters', 'every_days' => 1, 'minutes' => 5]);

        $wipes = collect(weekPlan()['items'])->where('title', 'Kitchen: Wipe counters');

        // Seven of them; Saturday's moves to Sunday
        expect($wipes)->toHaveCount(7)
            ->and($wipes->firstWhere('wanted', '2026-10-17'))->toMatchArray(['date' => '2026-10-18', 'moved' => true]);
    });
});

describe('Apply', function () {
    test('adds the ticked items to the calendar, at home time, and dates the to-do', function () {
        $keys = ["chore:{$this->mop->id}:2026-10-14", "todo:{$this->plumber->id}"];

        $this->actingAs($this->me)->post('/planner/apply', ['week_start' => '2026-10-12', 'keys' => $keys])
            ->assertRedirect()->assertSessionHasNoErrors();

        $mop = Event::where('title', '🧹 Kitchen: Mop')->sole();
        expect($mop->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-14 14:30')
            ->and($mop->ends_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-14 14:45')
            ->and($mop->user_id)->toBe($this->me->id)
            ->and(Event::where('title', '📌 Call the plumber')->exists())->toBeTrue()
            ->and($this->plumber->fresh()->due_on->toDateString())->toBe('2026-10-13');
    });

    test('applying twice does not add the same event twice', function () {
        $keys = ["chore:{$this->mop->id}:2026-10-14"];
        $this->actingAs($this->me)->post('/planner/apply', ['week_start' => '2026-10-12', 'keys' => $keys]);
        $this->post('/planner/apply', ['week_start' => '2026-10-12', 'keys' => $keys])->assertSessionHasNoErrors();

        expect(Event::where('title', '🧹 Kitchen: Mop')->count())->toBe(1);
    });

    test('an applied item keeps its time in the plan, and is not busy time for itself', function () {
        $this->actingAs($this->me)->post('/planner/apply', ['week_start' => '2026-10-12', 'keys' => ["chore:{$this->mop->id}:2026-10-14"]]);

        $mop = collect(weekPlan()['items'])->firstWhere('key', "chore:{$this->mop->id}:2026-10-14");
        expect($mop)->toMatchArray(['date' => '2026-10-14', 'start' => '17:30', 'applied' => true])
            ->and(weekPlan()['days'][2]['busy'])->toBe([]);
    });

    test('if the plan changed since the page was opened, nothing is saved', function () {
        $keys = ["chore:{$this->mop->id}:2026-10-14", "chore:{$this->mop->id}:2026-10-13"];

        $this->actingAs($this->me)->post('/planner/apply', ['week_start' => '2026-10-12', 'keys' => $keys])
            ->assertSessionHasErrors('keys');

        expect(Event::count())->toBe(2);
    });

    test('all or nothing: if one item fails, none are kept', function () {
        $created = 0;
        Event::creating(function () use (&$created) {
            if (++$created === 2) {
                throw new RuntimeException('Database went away');
            }
        });
        // The shelf goes first: its event is added and its to-do dated before the mop fails
        $keys = ["todo:{$this->shelf->id}", "chore:{$this->mop->id}:2026-10-14"];

        $this->withoutExceptionHandling();
        expect(fn () => $this->actingAs($this->me)->post('/planner/apply', ['week_start' => '2026-10-12', 'keys' => $keys]))
            ->toThrow(RuntimeException::class);

        expect(Event::count())->toBe(2)
            ->and($this->shelf->fresh()->due_on)->toBeNull();
    });

    test('another household\'s items cannot be applied', function () {
        $other = Household::factory()->create();
        $stranger = User::factory()->create();
        $other->addMember($stranger, HouseholdRole::Owner);

        $this->actingAs($stranger)->post('/planner/apply', ['week_start' => '2026-10-12', 'keys' => ["chore:{$this->mop->id}:2026-10-14"]])
            ->assertSessionHasErrors('keys');
        expect(Event::count())->toBe(2);
    });
});
