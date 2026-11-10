<?php

namespace App\Ai\Tools\Shopping;

use App\Ai\Tools\Concerns\HouseholdData;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\ShoppingItem;
use App\Queries\RunOutQuery;
use App\Queries\ShoppingDayQuery;

/**
 * "What do I need to buy?": the list itself, plus what the kitchen says will
 * run out before the next shop but isn't on the list yet.
 */
class GetShoppingList extends Tool
{
    use HouseholdData;

    public function name(): string
    {
        return 'get_shopping_list';
    }

    public function label(): string
    {
        return 'Read the shopping list';
    }

    public function description(): string
    {
        return 'What needs buying: the unchecked shopping list items by category (id for check_off_shopping_item, quantity, price if known, who added it), an estimated total, the usual shopping day, and kitchen items predicted to run out within a week that are not on the list yet (suggestions, not on the list).';
    }

    public function run(array $input, ToolContext $context): array
    {
        $h = $context->household;
        $items = $h->shoppingItems()->with('addedBy:id,name')->where('checked', false)
            ->orderBy('category')->orderBy('name')->get();

        $onList = $items->map(fn (ShoppingItem $i) => mb_strtolower(trim($i->name)))->all();
        $runningOut = array_values(array_filter(
            (new RunOutQuery($h, $context->today))->get(),
            fn (array $r) => $r['days_left'] <= 7 && ! in_array(mb_strtolower(trim($r['name'])), $onList, true),
        ));

        $usual = (new ShoppingDayQuery($h, $context->today))->get()['usual'];

        return [
            'items' => $items->map(fn (ShoppingItem $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'category' => $i->category->value,
                'quantity' => $i->quantity,
                'price' => $this->money($i->price),
                'added_by' => $i->addedBy?->name,
            ])->all(),
            'count' => $items->count(),
            'estimated_total' => $this->money($items->sum(fn (ShoppingItem $i) => (float) $i->price)),
            'items_without_price' => $items->whereNull('price')->count(),
            'currency' => $h->currency,
            'usual_shopping_day' => $usual ? ['day' => $usual['name'], 'next_on' => $usual['next_on']] : null,
            'suggested_not_on_list' => array_map(fn (array $r) => ['name' => $r['name'], 'runs_out_on' => $r['runs_out_on'], 'days_left' => $r['days_left']], $runningOut),
        ];
    }
}
