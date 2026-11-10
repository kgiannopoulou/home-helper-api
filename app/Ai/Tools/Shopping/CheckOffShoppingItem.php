<?php

namespace App\Ai\Tools\Shopping;

use App\Ai\Tools\Concerns\HouseholdData;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\ShoppingItem;
use Illuminate\Support\Facades\Gate;

class CheckOffShoppingItem extends Tool
{
    use HouseholdData;

    public function name(): string
    {
        return 'check_off_shopping_item';
    }

    public function label(): string
    {
        return 'Tick off a shopping item';
    }

    public function writes(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Tick an item off the shopping list (bought). Takes the item id from get_shopping_list.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['item_id' => $this->idSchema('shopping item')],
            'required' => ['item_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return ['item_id' => ['required', 'string', $this->idIn('shopping_items', $context)]];
    }

    public function authorize(ToolContext $context): bool
    {
        return Gate::forUser($context->user)->allows('create', [ShoppingItem::class, $context->household]);
    }

    public function describe(array $input, ToolContext $context): string
    {
        return 'Tick "'.$context->household->shoppingItems()->findOrFail((string) $input['item_id'])->name.'" off the shopping list';
    }

    public function run(array $input, ToolContext $context): array
    {
        $item = $context->household->shoppingItems()->findOrFail((string) $input['item_id']);
        Gate::forUser($context->user)->authorize('update', $item);
        $item->update(['checked' => true]);

        return ['checked_off' => $item->name];
    }
}
