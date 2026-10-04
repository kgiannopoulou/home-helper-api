<?php

use App\Enums\BudgetPeriod;
use App\Enums\ExpenseCategory;
use App\Enums\HouseholdRole;
use App\Models\Budget;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Expense;
use App\Models\Household;
use App\Models\Room;
use App\Models\SleepEntry;
use App\Models\StepCount;
use App\Models\User;
use App\Models\Workout;
use App\Notifications\HouseholdInvite;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-10-08 12:00', new DateTimeZone('Europe/Athens')));
    $this->home = Household::factory()->create(['name' => 'Patission 12', 'currency' => 'EUR']);
    $this->me = User::factory()->create(['name' => 'Konstantina']);
    $this->partner = User::factory()->create(['name' => 'Alex']);
    $this->home->addMember($this->me, HouseholdRole::Owner);
    $this->home->addMember($this->partner);
});

describe('access', function () {
    test('guests go to the login page', function (string $url) {
        $this->get($url)->assertRedirect('/login');
    })->with(['/dashboard', '/spending', '/health', '/chores', '/planner', '/household']);

    test('without a household, every page leads to the household page', function () {
        $this->actingAs(User::factory()->create());

        $this->get('/spending')->assertRedirect('/household');
        $this->get('/household')->assertInertia(fn (Assert $page) => $page
            ->component('household')
            ->where('household', null)
            ->where('members', []));
    });

    test('the same login shows the same household as on the phone', function () {
        $this->actingAs($this->me)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('household.id', $this->home->id)
            ->where('household.name', 'Patission 12')
            ->where('household.role', 'owner')
            ->has('households', 1));
    });

    test('you can switch to another household of yours, but not to someone else\'s', function () {
        $second = Household::factory()->create(['name' => 'Beach house']);
        $second->addMember($this->me);
        $stranger = Household::factory()->create();

        $this->actingAs($this->me)->post("/household/switch/{$second->id}")->assertRedirect();
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('household.name', 'Beach house')->has('households', 2));

        $this->post("/household/switch/{$stranger->id}")->assertForbidden();
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('household.name', 'Beach house'));
    });
});

describe('pages', function () {
    test('spending: every week of the range, and the month forecast', function () {
        Budget::factory()->for($this->home)->create(['period' => BudgetPeriod::Month, 'category' => null, 'amount' => 1000]);
        Expense::factory()->for($this->home)->create(['date' => '2026-10-06', 'amount' => 120, 'category' => ExpenseCategory::Groceries]);
        Expense::factory()->for($this->home)->create(['date' => '2026-09-29', 'amount' => 40, 'category' => ExpenseCategory::Fun]);

        $this->actingAs($this->me)->get('/spending?weeks=4')->assertInertia(fn (Assert $page) => $page
            ->component('spending')
            ->where('weeks', 4)
            ->where('weekStarts', ['2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05'])
            ->has('spending', 2)
            ->where('spending.1', ['week_start' => '2026-10-05', 'category' => 'groceries', 'total' => 120, 'expenses' => 1, 'previous_total' => null, 'change_pct' => null])
            ->where('forecast.budget', 1000)
            ->where('forecast.spent', 120)
            ->where('forecast.day', 8));

        $this->get('/spending?weeks=5')->assertSessionHasErrors('weeks');
    });

    test('health: your own weeks only, with the empty weeks still there', function () {
        foreach (['2026-10-05', '2026-10-06'] as $d) {
            SleepEntry::factory()->for($this->home)->for($this->me)->create(['date' => $d, 'bed_at' => "$d 00:00:00", 'woke_at' => "$d 07:30:00", 'quality' => 4]);
            StepCount::factory()->for($this->home)->for($this->me)->create(['date' => $d, 'steps' => 9000]);
        }
        StepCount::factory()->for($this->home)->for($this->me)->create(['date' => '2026-10-07', 'steps' => 6000]);
        Workout::factory()->for($this->home)->for($this->me)->create(['date' => '2026-10-06', 'minutes' => 30]);
        // The partner's never show up on my page
        StepCount::factory()->for($this->home)->for($this->partner)->create(['date' => '2026-10-06', 'steps' => 30000]);

        $this->actingAs($this->me)->get('/health')->assertInertia(fn (Assert $page) => $page
            ->component('health')
            ->has('weeks', 8)
            ->where('weeks.0.week_start', '2026-08-17')
            ->where('weeks.0', fn ($w) => $w['nights'] === 0 && $w['steps'] === 0 && $w['sleep_hours'] === null)
            ->where('weeks.7', ['week_start' => '2026-10-05', 'sleep_hours' => 7.5, 'sleep_quality' => 4, 'nights' => 2,
                'steps' => 24000, 'steps_per_day' => 8000, 'workouts' => 1, 'workout_minutes' => 30]));
    });

    test('chores: overdue, slipping and each member\'s fair share of minutes', function () {
        $room = Room::factory()->for($this->home)->create(['name' => 'Kitchen']);
        $mop = Chore::factory()->for($this->home)->for($room)->create(['name' => 'Mop', 'every_days' => 7, 'minutes' => 15]);
        ChoreCompletion::factory()->for($this->home)->for($mop)->create(['user_id' => $this->me->id, 'done_at' => '2026-09-28 10:00:00', 'minutes' => 15]);
        ChoreCompletion::factory()->for($this->home)->for($mop)->create(['user_id' => $this->partner->id, 'done_at' => '2026-09-20 10:00:00', 'minutes' => 45]);
        // Older than 30 days: not in the fair share
        ChoreCompletion::factory()->for($this->home)->for($mop)->create(['user_id' => $this->partner->id, 'done_at' => '2026-08-01 10:00:00', 'minutes' => 300]);

        $this->actingAs($this->me)->get('/chores')->assertInertia(fn (Assert $page) => $page
            ->component('chores')
            ->where('overdue.0.name', 'Mop')
            ->where('overdue.0.days_overdue', 3)
            ->has('slipping')
            ->where('days', 30)
            ->where('fairShare', [
                ['user_id' => $this->partner->id, 'name' => 'Alex', 'minutes' => 45, 'chores' => 1, 'share' => 0.75],
                ['user_id' => $this->me->id, 'name' => 'Konstantina', 'minutes' => 15, 'chores' => 1, 'share' => 0.25],
            ]));
    });

    test('dashboard and planner render', function () {
        $this->actingAs($this->me);

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('dashboard')->has('forecast')->where('toBuy', 0));
        $this->get('/planner')->assertInertia(fn (Assert $page) => $page->component('planner')->where('plan.week_start', '2026-10-12')->has('plan.days', 7));
    });
});

describe('household', function () {
    test('members and waiting invites', function () {
        Notification::fake();

        $this->actingAs($this->me)->post('/household/invites', ['email' => 'eleni@example.com'])->assertRedirect();
        $this->post('/household/invites', ['email' => $this->partner->email])->assertSessionHasErrors('email');

        Notification::assertSentOnDemand(HouseholdInvite::class);
        $this->get('/household')->assertInertia(fn (Assert $page) => $page
            ->component('household')
            ->where('canInvite', true)
            ->has('members', 2)
            ->where('members.0.name', 'Alex')
            ->where('members.1.role', 'owner')
            ->where('invites.0.email', 'eleni@example.com'));
    });

    test('only the owner invites', function () {
        $this->actingAs($this->partner)->post('/household/invites', ['email' => 'eleni@example.com'])->assertForbidden();
        $this->get('/household')->assertInertia(fn (Assert $page) => $page->where('canInvite', false));
    });

    test('a new household makes you its owner and becomes the one you see', function () {
        $this->actingAs($this->me)->post('/household', ['name' => 'Beach house', 'currency' => 'eur'])->assertRedirect('/household');

        $beach = Household::where('name', 'Beach house')->sole();
        expect($beach->currency)->toBe('EUR')
            ->and($this->me->roleIn($beach))->toBe(HouseholdRole::Owner);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('household.id', $beach->id));
    });
});
