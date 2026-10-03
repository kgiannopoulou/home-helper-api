<?php

namespace Database\Seeders;

use App\Enums\AdminKind;
use App\Enums\BudgetPeriod;
use App\Enums\Drink;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseSource;
use App\Enums\FoodSource;
use App\Enums\HouseholdRole;
use App\Enums\Intensity;
use App\Enums\ItemCategory;
use App\Enums\Meal;
use App\Enums\ShoppingSource;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Enums\SupplyLevel;
use App\Enums\TripSource;
use App\Enums\WorkoutSource;
use App\Enums\WorkoutType;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One household with 6 months of realistic data, ending today:
 * - a weekly shop every Saturday, with staples bought on a rhythm and prices that creep up
 * - rent and bills on their day each month
 * - one month over budget (the washing machine broke)
 * - "Clean the oven" set to every 14 days but done every 3–5 weeks
 * - food, water, sleep, workouts and a slowly falling weight for the owner
 *
 * The same random seed every time, so the numbers in the README stay true.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private Household $home;

    private User $owner;

    private User $partner;

    private Carbon $start;

    private Carbon $today;

    public function run(): void
    {
        mt_srand(2026);
        fake()->seed(2026);

        $this->today = Carbon::today();
        $this->start = $this->today->copy()->subMonthsNoOverflow(6)->startOfMonth();

        DB::transaction(function () {
            $this->people();
            $this->money();
            $this->kitchenAndShopping();
            $this->chores();
            $this->health();
            $this->planner();
        });
    }

    private function people(): void
    {
        $this->owner = User::factory()->create([
            'name' => 'Konstantina',
            'email' => 'demo@homehelper.test',
        ]);
        $this->partner = User::factory()->create([
            'name' => 'Alex',
            'email' => 'alex@homehelper.test',
        ]);

        $this->home = Household::create(['name' => 'Patission 12', 'currency' => 'EUR']);
        $this->home->addMember($this->owner, HouseholdRole::Owner);
        $this->home->addMember($this->partner);

        $this->home->invites()->create([
            'email' => 'maria@example.com',
            'role' => HouseholdRole::Member,
            'expires_at' => $this->today->copy()->addDays(5),
            'invited_by' => $this->owner->id,
        ]);
    }

    private function money(): void
    {
        $this->home->budgets()->createMany([
            ['period' => BudgetPeriod::Month, 'category' => null, 'amount' => 1650],
            ['period' => BudgetPeriod::Week, 'category' => ExpenseCategory::Groceries, 'amount' => 90],
            ['period' => BudgetPeriod::Month, 'category' => ExpenseCategory::EatingOut, 'amount' => 140],
            ['period' => BudgetPeriod::Month, 'category' => ExpenseCategory::Fun, 'amount' => 80],
        ]);

        $bills = $this->home->recurringBills()->createMany([
            ['name' => 'Rent', 'amount' => 620, 'category' => ExpenseCategory::Bills, 'day_of_month' => 1],
            ['name' => 'Phone', 'amount' => 24.90, 'category' => ExpenseCategory::Bills, 'day_of_month' => 5],
            ['name' => 'Internet', 'amount' => 32, 'category' => ExpenseCategory::Bills, 'day_of_month' => 10],
            ['name' => 'Electricity', 'amount' => 68, 'category' => ExpenseCategory::Bills, 'day_of_month' => 15],
            ['name' => 'Gym', 'amount' => 35, 'category' => ExpenseCategory::Health, 'day_of_month' => 3],
            ['name' => 'Netflix', 'amount' => 13.99, 'category' => ExpenseCategory::Fun, 'day_of_month' => 20],
        ]);

        // Each bill once a month on its day
        for ($month = $this->start->copy()->startOfMonth(); $month <= $this->today; $month->addMonthNoOverflow()) {
            foreach ($bills as $bill) {
                $day = $month->copy()->day($bill->day_of_month);
                if ($day < $this->start || $day > $this->today) {
                    continue;
                }
                $this->home->expenses()->create([
                    'user_id' => $this->owner->id,
                    'recurring_bill_id' => $bill->id,
                    'date' => $day,
                    'amount' => $bill->amount,
                    'category' => $bill->category,
                    'note' => $bill->name,
                    'source' => ExpenseSource::Recurring,
                ]);
            }
        }

        // Everyday spending
        foreach ($this->days() as $day) {
            $who = mt_rand(0, 2) ? $this->owner : $this->partner;
            if (in_array($day->dayOfWeek, [Carbon::FRIDAY, Carbon::SUNDAY]) && mt_rand(1, 10) <= 7) {
                $this->spend($day, $who, ExpenseCategory::EatingOut, mt_rand(1200, 4800) / 100, fake()->randomElement(['Souvlaki', 'Pizza night', 'Taverna', 'Brunch', 'Coffee and cake']));
            }
            if ($day->isWeekday() && mt_rand(1, 10) <= 3) {
                $this->spend($day, $who, ExpenseCategory::Transport, fake()->randomElement([1.20, 1.20, 4.50, 30.00]), fake()->randomElement(['Metro', 'Metro', 'Taxi', 'Petrol']));
            }
            if (mt_rand(1, 14) === 1) {
                $this->spend($day, $who, ExpenseCategory::Fun, mt_rand(800, 3500) / 100, fake()->randomElement(['Cinema', 'Concert', 'Book', 'Board game']));
            }
            if (mt_rand(1, 25) === 1) {
                $this->spend($day, $who, ExpenseCategory::Health, mt_rand(600, 4000) / 100, fake()->randomElement(['Pharmacy', 'Vitamins', 'Physio']));
            }
            if (mt_rand(1, 20) === 1) {
                $this->spend($day, $who, ExpenseCategory::Household, mt_rand(500, 3000) / 100, fake()->randomElement(['Light bulbs', 'Plant pot', 'Towels', 'Hangers']));
            }
        }

        // The month over budget, three months ago: the washing machine broke
        $broke = $this->today->copy()->subMonthsNoOverflow(3)->startOfMonth()->addDays(11);
        $this->spend($broke, $this->owner, ExpenseCategory::Household, 489.00, 'New washing machine');
        $this->spend($broke->copy()->addDays(1), $this->owner, ExpenseCategory::Household, 45.00, 'Laundromat while waiting');
        $this->spend($broke->copy()->addDays(9), $this->partner, ExpenseCategory::EatingOut, 96.50, 'Birthday dinner');
    }

    private function kitchenAndShopping(): void
    {
        // name, category, location, buy every N weeks, first price, monthly price rise
        $staples = [
            ['Milk', ItemCategory::Drinks, StorageLocation::Fridge, 1, 1.49, 0.01],
            ['Bread', ItemCategory::Food, StorageLocation::Pantry, 1, 1.20, 0.00],
            ['Eggs', ItemCategory::Food, StorageLocation::Fridge, 2, 3.10, 0.04],
            ['Greek yoghurt', ItemCategory::Food, StorageLocation::Fridge, 1, 2.35, 0.02],
            ['Feta', ItemCategory::Food, StorageLocation::Fridge, 2, 4.80, 0.06],
            ['Tomatoes', ItemCategory::Food, StorageLocation::Fridge, 1, 2.40, 0.00],
            ['Olive oil', ItemCategory::Food, StorageLocation::Pantry, 6, 9.90, 0.35],
            ['Coffee', ItemCategory::Drinks, StorageLocation::Pantry, 3, 6.50, 0.12],
            ['Pasta', ItemCategory::Food, StorageLocation::Pantry, 3, 1.15, 0.00],
            ['Chicken breast', ItemCategory::Food, StorageLocation::Freezer, 2, 7.90, 0.05],
            ['Dish soap', ItemCategory::Cleaning, StorageLocation::Cleaning, 5, 2.60, 0.00],
            ['Toilet paper', ItemCategory::Bathroom, StorageLocation::Bathroom, 3, 5.40, 0.05],
        ];

        $items = [];
        foreach ($staples as [$name, $category, $location]) {
            $items[$name] = $this->home->inventoryItems()->create([
                'name' => $name,
                'category' => $category,
                'location' => $location,
                'level' => StockLevel::Full,
            ]);
        }

        $stores = ['Lidl', 'Sklavenitis', 'Lidl', 'AB Vassilopoulos'];
        $week = 0;
        for ($saturday = $this->start->copy()->next(Carbon::SATURDAY); $saturday <= $this->today; $saturday->addWeek(), $week++) {
            // One Saturday in eight is skipped (away, or ordered online), so the habit isn't perfect
            if (mt_rand(1, 8) === 1) {
                continue;
            }
            $who = $week % 3 === 2 ? $this->partner : $this->owner;
            $trip = $this->home->shoppingTrips()->create([
                'user_id' => $who->id,
                'date' => $saturday,
                'store' => $stores[$week % count($stores)],
                'total' => 0,
                'source' => $week % 2 ? TripSource::Receipt : TripSource::Manual,
            ]);

            $total = 0;
            $count = 0;
            $months = $this->start->diffInMonths($saturday);
            foreach ($staples as [$name, , , $everyWeeks, $price, $rise]) {
                if ($week % $everyWeeks !== 0) {
                    continue;
                }
                $paid = round($price + $rise * $months + mt_rand(-10, 10) / 100, 2);
                $this->home->purchases()->create([
                    'inventory_item_id' => $items[$name]->id,
                    'shopping_trip_id' => $trip->id,
                    'bought_on' => $saturday,
                    'price' => $paid,
                ]);
                $this->home->itemPrices()->create([
                    'shopping_trip_id' => $trip->id,
                    'name' => mb_strtolower($name),
                    'price' => $paid,
                    'seen_on' => $saturday,
                ]);
                $total += $paid;
                $count++;
            }
            // Fruit, vegetables and the odd treat that aren't tracked in the kitchen
            $extra = mt_rand(2500, 5500) / 100;
            $total += $extra;
            $count += mt_rand(5, 14);

            $trip->update(['total' => round($total, 2), 'item_count' => $count]);
            $this->home->expenses()->create([
                'user_id' => $who->id,
                'date' => $saturday,
                'amount' => round($total, 2),
                'category' => ExpenseCategory::Groceries,
                'note' => $trip->store,
                'source' => ExpenseSource::Shopping,
            ]);
        }

        // Today's kitchen: some things running low, some about to expire
        $items['Milk']->update(['level' => StockLevel::Low, 'expires_on' => $this->today->copy()->addDays(2)]);
        $items['Greek yoghurt']->update(['level' => StockLevel::Half, 'expires_on' => $this->today->copy()->addDays(1)]);
        $items['Feta']->update(['expires_on' => $this->today->copy()->addDays(9)]);
        $items['Coffee']->update(['level' => StockLevel::Empty]);
        $items['Olive oil']->update(['level' => StockLevel::Low]);

        $this->home->shoppingItems()->createMany([
            ['added_by' => $this->owner->id, 'name' => 'Coffee', 'category' => ItemCategory::Drinks, 'source' => ShoppingSource::Inventory],
            ['added_by' => $this->owner->id, 'name' => 'Milk', 'category' => ItemCategory::Drinks, 'quantity' => '2', 'source' => ShoppingSource::Inventory],
            ['added_by' => $this->partner->id, 'name' => 'Bananas', 'category' => ItemCategory::Food, 'quantity' => '1 kg'],
            ['added_by' => $this->partner->id, 'name' => 'Bin bags', 'category' => ItemCategory::Cleaning],
            ['added_by' => $this->owner->id, 'name' => 'Basil plant', 'category' => ItemCategory::Home, 'price' => 3.50, 'checked' => true],
        ]);

        $this->home->supplies()->createMany([
            ['name' => 'Dish soap', 'category' => ItemCategory::Cleaning, 'level' => SupplyLevel::Half],
            ['name' => 'Bleach', 'category' => ItemCategory::Cleaning, 'level' => SupplyLevel::Full],
            ['name' => 'Bin bags', 'category' => ItemCategory::Cleaning, 'level' => SupplyLevel::Out],
            ['name' => 'Glass cleaner', 'category' => ItemCategory::Cleaning, 'level' => SupplyLevel::Low],
        ]);
    }

    private function chores(): void
    {
        // room => [emoji, [[chore, every days, minutes, assignee], …]]
        $plan = [
            'Kitchen' => ['🍳', [['Wipe counters', 1, 5, null], ['Mop floor', 7, 15, 'partner'], ['Clean the oven', 14, 40, 'owner'], ['Defrost freezer', 90, 30, null]]],
            'Bathroom' => ['🛁', [['Clean toilet', 7, 10, 'partner'], ['Scrub shower', 7, 20, 'owner'], ['Wash towels', 7, 5, null]]],
            'Living room' => ['🛋️', [['Vacuum', 7, 20, 'owner'], ['Dust shelves', 14, 15, null]]],
            'Bedroom' => ['🛏️', [['Change sheets', 14, 15, null], ['Vacuum', 7, 10, 'partner']]],
            'Plants' => ['🪴', [['Water plants', 3, 5, 'owner']]],
        ];

        foreach ($plan as $roomName => [$emoji, $chores]) {
            $room = $this->home->rooms()->create([
                'name' => $roomName,
                'emoji' => $emoji,
                'personal' => $roomName === 'Plants',
            ]);

            foreach ($chores as [$name, $every, $minutes, $assignee]) {
                $chore = $this->home->chores()->create([
                    'room_id' => $room->id,
                    'assignee_id' => $assignee ? $this->{$assignee}->id : null,
                    'name' => $name,
                    'every_days' => $every,
                    'minutes' => $minutes,
                ]);

                $slips = $name === 'Clean the oven';
                $at = $this->start->copy()->addDays(mt_rand(0, min($every, 10)));
                while ($at <= $this->today) {
                    $doer = $assignee ? $this->{$assignee} : (mt_rand(0, 1) ? $this->owner : $this->partner);
                    $this->home->choreCompletions()->create([
                        'chore_id' => $chore->id,
                        'user_id' => $doer->id,
                        'done_at' => $at->copy()->setTime(mt_rand(9, 20), mt_rand(0, 59)),
                        'minutes' => max(1, $minutes + mt_rand(-3, 6)),
                    ]);
                    // Most chores happen around their schedule; the oven keeps getting put off
                    $gap = $slips ? mt_rand(22, 36) : max(1, $every + mt_rand(-1, (int) ceil($every / 4)));
                    $at->addDays($gap);
                }
            }
        }
    }

    private function health(): void
    {
        $kg = 71.8;
        $mealPlan = [
            [Meal::Breakfast, '08:10', [['Greek yoghurt with honey', 250, 230, 20, 22, 7, 0], ['Toast with feta', 120, 310, 13, 32, 14, 3]]],
            [Meal::Lunch, '13:30', [['Lentil soup', 400, 360, 22, 52, 6, 15], ['Chicken salad', 350, 430, 38, 18, 22, 6], ['Spanakopita', 200, 520, 14, 40, 33, 4]]],
            [Meal::Dinner, '20:15', [['Pasta with tomato sauce', 380, 560, 18, 92, 12, 7], ['Grilled fish and greens', 400, 450, 42, 12, 24, 6], ['Omelette', 250, 380, 26, 4, 28, 1]]],
            [Meal::Snack, '17:00', [['Apple', 180, 95, 0.5, 25, 0.3, 4.4], ['Handful of walnuts', 30, 196, 4.6, 4, 19.6, 2]]],
        ];

        foreach ($this->days() as $i => $day) {
            $user = $this->owner->id;

            foreach ($mealPlan as [$meal, $time, $options]) {
                if ($meal === Meal::Snack && mt_rand(0, 2) === 0) {
                    continue;
                }
                [$name, $grams, $kcal, $protein, $carbs, $fat, $fiber] = $options[array_rand($options)];
                $this->home->foodEntries()->create([
                    'user_id' => $user,
                    'date' => $day,
                    'eaten_at' => $day->copy()->setTimeFromTimeString($time)->addMinutes(mt_rand(-20, 20)),
                    'name' => $name,
                    'meal' => $meal,
                    'grams' => $grams,
                    'kcal' => $kcal,
                    'protein' => $protein,
                    'carbs' => $carbs,
                    'fat' => $fat,
                    'fiber' => $fiber,
                    'source' => FoodSource::Database,
                ]);
            }

            $glasses = mt_rand(4, 8);
            for ($g = 0; $g < $glasses; $g++) {
                $this->home->waterEntries()->create([
                    'user_id' => $user,
                    'date' => $day,
                    'drunk_at' => $day->copy()->setTime(8 + $g * 2, mt_rand(0, 59)),
                    'ml' => fake()->randomElement([250, 330, 500]),
                    'drink' => $g === 0 ? Drink::Coffee : Drink::Water,
                ]);
            }

            // $day is the morning you woke up; at the weekend you go to bed after midnight and sleep in
            $weekend = $day->isWeekend();
            $bed = $weekend
                ? $day->copy()->setTime(0, mt_rand(15, 59))
                : $day->copy()->subDay()->setTime(23, mt_rand(0, 59));
            $this->home->sleepEntries()->create([
                'user_id' => $user,
                'date' => $day,
                'bed_at' => $bed,
                'woke_at' => $day->copy()->setTime($weekend ? 9 : 7, mt_rand(0, 40)),
                'quality' => mt_rand($weekend ? 3 : 2, 5),
            ]);

            if (in_array($day->dayOfWeek, [Carbon::MONDAY, Carbon::WEDNESDAY, Carbon::SATURDAY]) && mt_rand(1, 10) <= 8) {
                $run = $day->dayOfWeek !== Carbon::WEDNESDAY;
                $minutes = $run ? 20 + intdiv($i, 14) : 45;
                $this->home->workouts()->create([
                    'user_id' => $user,
                    'date' => $day,
                    'type' => $run ? WorkoutType::Run : WorkoutType::Gym,
                    'minutes' => min($minutes, 45),
                    'intensity' => $run ? Intensity::Moderate : Intensity::Hard,
                    'kcal' => min($minutes, 45) * ($run ? 9 : 7),
                    'source' => $run ? WorkoutSource::Coach : WorkoutSource::Manual,
                ]);
            }

            if ($day->isSunday()) {
                $kg = round($kg - 0.12 + mt_rand(-25, 20) / 100, 1);
                $this->home->weights()->create(['user_id' => $user, 'date' => $day, 'kg' => $kg]);
            }
        }
    }

    private function planner(): void
    {
        $at = fn (int $days, string $time) => $this->today->copy()->addDays($days)->setTimeFromTimeString($time);

        $this->home->events()->createMany([
            ['user_id' => $this->owner->id, 'title' => 'Dentist', 'starts_at' => $at(3, '10:30'), 'ends_at' => $at(3, '11:15'), 'location' => 'Dr. Papadaki, Kypseli'],
            ['user_id' => $this->partner->id, 'title' => 'Football', 'starts_at' => $at(1, '19:00'), 'ends_at' => $at(1, '20:30')],
            ['user_id' => $this->owner->id, 'title' => 'Dinner at Eleni\'s', 'starts_at' => $at(6, '21:00'), 'ends_at' => $at(6, '23:30')],
            ['user_id' => $this->owner->id, 'title' => 'Weekend in Nafplio', 'starts_at' => $at(13, '00:00'), 'ends_at' => $at(15, '23:59'), 'all_day' => true, 'location' => 'Nafplio'],
        ]);

        $this->home->todos()->createMany([
            ['user_id' => $this->owner->id, 'title' => 'Call the plumber about the drip', 'due_on' => $this->today->copy()->addDays(1), 'minutes' => 10],
            ['user_id' => $this->owner->id, 'title' => 'Renew passport', 'due_on' => $this->today->copy()->addDays(20), 'minutes' => 60],
            ['user_id' => $this->partner->id, 'title' => 'Return library books', 'due_on' => $this->today->copy()->subDays(2), 'minutes' => 20],
            ['user_id' => $this->owner->id, 'title' => 'Sort out winter clothes', 'minutes' => 45],
            ['user_id' => $this->partner->id, 'title' => 'Buy a birthday card', 'done_at' => $this->today->copy()->subDays(3)->setTime(18, 0)],
        ]);

        $this->home->adminItems()->createMany([
            ['title' => 'Car insurance', 'kind' => AdminKind::Renewal, 'due_on' => $this->today->copy()->addDays(18), 'repeat_months' => 12, 'remind_days' => 14, 'amount' => 310],
            ['title' => 'Boiler service', 'kind' => AdminKind::Appointment, 'due_on' => $this->today->copy()->addDays(9), 'repeat_months' => 12, 'remind_days' => 7, 'amount' => 60],
            ['title' => 'Property tax (ENFIA) instalment', 'kind' => AdminKind::Bill, 'due_on' => $this->today->copy()->endOfMonth(), 'repeat_months' => 1, 'remind_days' => 5, 'amount' => 42.17],
            ['title' => 'Dentist check-up', 'kind' => AdminKind::Appointment, 'due_on' => $this->today->copy()->addDays(3), 'repeat_months' => 6, 'remind_days' => 3],
        ]);
    }

    private function spend(Carbon $day, User $who, ExpenseCategory $category, float $amount, string $note): void
    {
        $this->home->expenses()->create([
            'user_id' => $who->id,
            'date' => $day,
            'amount' => $amount,
            'category' => $category,
            'note' => $note,
            'source' => ExpenseSource::Manual,
        ]);
    }

    /**
     * Every day from the start to today.
     *
     * @return list<Carbon>
     */
    private function days(): array
    {
        $days = [];
        for ($d = $this->start->copy(); $d <= $this->today; $d->addDay()) {
            $days[] = $d->copy();
        }

        return $days;
    }
}
