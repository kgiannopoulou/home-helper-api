<?php

namespace App\Jobs;

use App\Enums\ShoppingSource;
use App\Enums\StockLevel;
use App\Enums\SupplyLevel;
use App\Models\Household;
use App\Push\ExpoPush;
use App\Queries\RunOutQuery;
use App\Queries\ShoppingDayQuery;
use App\Support\ItemName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The evening before the usual shopping day, puts on the shared list what's low or
 * empty and what will run out before the shop after it, then tells the household.
 * Same rules as itemsForShoppingDay() on the phone, but it no longer needs the app open.
 *
 * Running it twice is harmless: what's already on the list is skipped, and with
 * nothing added there is no push.
 */
class PrepareShoppingList extends HouseholdJob
{
    protected function run(Household $household, CarbonImmutable $today): array
    {
        $usual = (new ShoppingDayQuery($household, $today))->get()['usual'];
        if (! $usual) {
            return ['skipped' => 'no usual shopping day yet'];
        }
        $shoppingDay = CarbonImmutable::parse($usual['next_on'], $today->timezone);
        if (! $shoppingDay->isSameDay($today->addDay())) {
            return ['skipped' => 'not the day before', 'shopping_day' => $usual['next_on']];
        }

        $toBuy = $this->toBuy($household, $today, $shoppingDay);
        DB::transaction(function () use ($household, $toBuy) {
            foreach ($toBuy as $item) {
                $household->shoppingItems()->create([
                    'name' => $item['name'],
                    'category' => $item['category'],
                    'source' => ShoppingSource::Inventory,
                ]);
            }
        });

        $push = null;
        if ($toBuy) {
            $push = app(ExpoPush::class)->toUsers(
                $household->users,
                "🛒 Tomorrow is {$usual['name']} shopping",
                self::listText(array_column($toBuy, 'name')),
                ['url' => '/shopping'],
            );
        }

        return ['shopping_day' => $usual['next_on'], 'added' => $toBuy, 'push' => $push];
    }

    /**
     * @return list<array{name: string, category: string, why: string}>
     */
    private function toBuy(Household $household, CarbonImmutable $today, CarbonImmutable $shoppingDay): array
    {
        // Whatever runs out before the shop after this one has to be bought now
        $nextShop = $shoppingDay->addDays(7)->toDateString();
        $runsOut = collect((new RunOutQuery($household, $today))->get())->keyBy('id');

        $listed = $household->shoppingItems()->where('checked', false)->pluck('name')
            ->mapWithKeys(fn (string $name) => [ItemName::normalize($name) => true])
            ->all();
        $out = [];
        $add = function (string $name, string $category, string $why) use (&$listed, &$out) {
            $key = ItemName::normalize($name);
            if (isset($listed[$key])) {
                return;
            }
            $listed[$key] = true;
            $out[] = ['name' => $name, 'category' => $category, 'why' => $why];
        };

        foreach ($household->inventoryItems()->orderBy('name')->get() as $item) {
            if (in_array($item->level, [StockLevel::Empty, StockLevel::Low], true)) {
                $add($item->name, $item->category->value, $item->level->value);

                continue;
            }
            $runOut = $runsOut->get($item->id);
            if ($runOut && $runOut['runs_out_on'] < $nextShop) {
                $add($item->name, $item->category->value, "runs out around {$runOut['runs_out_on']}");
            }
        }
        foreach ($household->supplies()->orderBy('name')->get() as $supply) {
            if (in_array($supply->level, [SupplyLevel::Low, SupplyLevel::Out], true)) {
                $add($supply->name, $supply->category->value, $supply->level === SupplyLevel::Out ? 'empty' : 'low');
            }
        }

        return $out;
    }

    /**
     * "Added Milk, Eggs and Olive oil to the list." / "Added Milk, Eggs, Bread and 4 more to the list."
     *
     * @param  list<string>  $names
     */
    public static function listText(array $names): string
    {
        $count = count($names);
        $shown = $count > 4 ? [...array_slice($names, 0, 3), ($count - 3).' more'] : $names;
        $last = array_pop($shown);
        $text = $shown ? implode(', ', $shown)." and {$last}" : $last;

        return "Added {$text} to the list.";
    }
}
