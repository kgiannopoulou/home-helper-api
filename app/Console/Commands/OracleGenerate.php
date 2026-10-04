<?php

namespace App\Console\Commands;

use App\Enums\ExpenseCategory;
use App\Enums\Meal;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Writes years of household data as CSV files for the Oracle lab (ops/oracle),
 * loaded there with SQL*Loader. The households live the same way as the demo
 * household (DemoSeeder): a weekly shop on their usual day, bills on their day of
 * the month, meals logged, chores done around their schedule, to-dos. Always the
 * same numbers for the same options (a fixed random seed); only the ids differ.
 */
#[Signature('oracle:generate {--from=2023-01-01 : First day} {--to= : Last day (default today)} {--households=40} {--out=ops/oracle/data}')]
#[Description('Generate CSV data for the Oracle lab')]
class OracleGenerate extends Command
{
    /** @var array<string, resource> */
    private array $files = [];

    private int $userId = 0;

    public function handle(): int
    {
        mt_srand(2026);
        $from = CarbonImmutable::parse($this->option('from'));
        $to = CarbonImmutable::parse($this->option('to') ?? 'today');
        $out = base_path($this->option('out'));
        File::ensureDirectoryExists($out);

        $columns = [
            'households' => ['id', 'name', 'currency', 'created_at'],
            'users' => ['id', 'name', 'email', 'created_at'],
            'household_users' => ['household_id', 'user_id', 'role'],
            'recurring_bills' => ['id', 'household_id', 'name', 'amount', 'category', 'day_of_month'],
            'expenses' => ['id', 'household_id', 'user_id', 'recurring_bill_id', 'entry_date', 'amount', 'category', 'source', 'note'],
            'rooms' => ['id', 'household_id', 'name'],
            'chores' => ['id', 'household_id', 'room_id', 'name', 'every_days', 'minutes'],
            'chore_completions' => ['id', 'household_id', 'chore_id', 'user_id', 'done_at', 'minutes'],
            'food_entries' => ['id', 'household_id', 'user_id', 'entry_date', 'eaten_at', 'name', 'meal', 'kcal', 'protein', 'fiber'],
            'todos' => ['id', 'household_id', 'user_id', 'title', 'due_on', 'minutes', 'done_at'],
        ];
        foreach ($columns as $name => $header) {
            $this->files[$name] = fopen("{$out}/{$name}.csv", 'w');
            fputcsv($this->files[$name], $header);
        }

        $count = (int) $this->option('households');
        $bar = $this->output->createProgressBar($count);
        for ($h = 1; $h <= $count; $h++) {
            $this->household($h, $from, $to);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        foreach ($this->files as $name => $file) {
            fclose($file);
            $rows = count(file("{$out}/{$name}.csv")) - 1;
            $this->line(sprintf('%-18s %8s rows', $name, number_format($rows)));
        }

        return self::SUCCESS;
    }

    private function household(int $n, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $id = $this->id();
        $this->write('households', [$id, "Household {$n}", 'EUR', $from->toDateString()]);
        $owner = $this->user($id, 'owner', $from);
        $partner = $this->user($id, 'member', $from);
        $people = [$owner, $partner];

        // Bills on their day each month; electricity costs more in winter and summer
        $bills = [];
        foreach ([['Rent', 1, mt_rand(450, 900)], ['Phone', 5, 24.9], ['Internet', 10, 32], ['Electricity', 15, 0]] as [$name, $day, $amount]) {
            $billId = $this->id();
            $bills[] = [$billId, $name, $day, $amount];
            $this->write('recurring_bills', [$billId, $id, $name, $amount ?: 60, ExpenseCategory::Bills->value, $day]);
        }

        // Rooms and chores: [room, chore, every days, minutes]
        $chores = [];
        $roomIds = [];
        foreach ([['Kitchen', 'Wipe counters', 1, 5], ['Kitchen', 'Mop floor', 7, 15], ['Kitchen', 'Clean the oven', 14, 40],
            ['Bathroom', 'Clean toilet', 7, 10], ['Bathroom', 'Scrub shower', 7, 20], ['Living room', 'Vacuum', 7, 20],
            ['Living room', 'Dust shelves', 14, 15], ['Bedroom', 'Change sheets', 14, 15]] as [$room, $name, $every, $minutes]) {
            if (! isset($roomIds[$room])) {
                $roomIds[$room] = $this->id();
                $this->write('rooms', [$roomIds[$room], $id, $room]);
            }
            $choreId = $this->id();
            $chores[] = [$choreId, $every, $minutes, $from->addDays(mt_rand(0, $every - 1))];
            $this->write('chores', [$choreId, $id, $roomIds[$room], $name, $every, $minutes]);
        }

        $shopDay = mt_rand(0, 6);
        $meals = [
            [Meal::Breakfast, '08:00', [['Greek yoghurt', 230, 20, 0], ['Toast with feta', 310, 13, 3]]],
            [Meal::Lunch, '13:30', [['Lentil soup', 360, 22, 15], ['Chicken salad', 430, 38, 6]]],
            [Meal::Dinner, '20:00', [['Pasta with tomato sauce', 560, 18, 7], ['Grilled fish and greens', 450, 42, 6]]],
        ];

        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();

            foreach ($bills as [$billId, $name, $dayOfMonth, $amount]) {
                if ($day->day === $dayOfMonth) {
                    $amount = $amount ?: round(45 + 35 * abs(cos(($day->month - 1) / 12 * 2 * M_PI)) + mt_rand(0, 900) / 100, 2);
                    $this->write('expenses', [$this->id(), $id, null, $billId, $date, $amount, ExpenseCategory::Bills->value, 'recurring', $name]);
                }
            }
            if ($day->dayOfWeek === $shopDay && mt_rand(1, 8) > 1) {
                $this->write('expenses', [$this->id(), $id, $people[mt_rand(0, 1)], null, $date, mt_rand(4500, 12000) / 100, ExpenseCategory::Groceries->value, 'shopping', 'Weekly shop']);
            }
            if (mt_rand(1, 2) === 1) {
                $category = [ExpenseCategory::EatingOut, ExpenseCategory::Transport, ExpenseCategory::Fun, ExpenseCategory::Household, ExpenseCategory::Health][mt_rand(0, 4)];
                $this->write('expenses', [$this->id(), $id, $people[mt_rand(0, 1)], null, $date, mt_rand(400, 6000) / 100, $category->value, 'manual', null]);
            }

            // The owner logs meals, most days
            if (mt_rand(1, 10) <= 9) {
                foreach ($meals as [$meal, $time, $options]) {
                    [$name, $kcal, $protein, $fiber] = $options[mt_rand(0, 1)];
                    $this->write('food_entries', [$this->id(), $id, $owner, $date, "{$date} {$time}:00", $name, $meal->value, $kcal, $protein, $fiber]);
                }
            }

            foreach ($chores as $i => [$choreId, $every, $minutes, $next]) {
                if ($day->isSameDay($next)) {
                    $at = $day->setTime(mt_rand(9, 20), mt_rand(0, 59))->format('Y-m-d H:i:s');
                    $this->write('chore_completions', [$this->id(), $id, $choreId, $people[mt_rand(0, 1)], $at, max(1, $minutes + mt_rand(-3, 6))]);
                    $chores[$i][3] = $day->addDays(max(1, $every + mt_rand(-1, (int) ceil($every / 4))));
                }
            }

            if ($day->dayOfWeek === 1 && mt_rand(1, 3) > 1) {
                $due = $day->addDays(mt_rand(1, 6));
                $done = $due <= $to && mt_rand(1, 10) <= 8 ? $due->setTime(18, 0)->format('Y-m-d H:i:s') : null;
                $this->write('todos', [$this->id(), $id, $owner, 'To-do '.mt_rand(100, 999), $due->toDateString(), mt_rand(1, 6) * 10, $done]);
            }
        }
    }

    private function user(string $householdId, string $role, CarbonImmutable $from): int
    {
        $id = ++$this->userId;
        $this->write('users', [$id, "Person {$id}", "person{$id}@example.test", $from->toDateString()]);
        $this->write('household_users', [$householdId, $id, $role]);

        return $id;
    }

    private function id(): string
    {
        return strtolower((string) Str::ulid());
    }

    /**
     * @param  list<mixed>  $row
     */
    private function write(string $file, array $row): void
    {
        fputcsv($this->files[$file], $row);
    }
}
