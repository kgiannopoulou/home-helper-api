<?php

namespace App\Ai\Tools\Shopping;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Enums\ItemCategory;
use App\Enums\ShoppingSource;
use App\Models\ShoppingItem;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AddShoppingItem extends Tool
{
    public function name(): string
    {
        return 'add_shopping_item';
    }

    public function label(): string
    {
        return 'Add to the shopping list';
    }

    public function writes(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Put one item on the shopping list. Check get_shopping_list first so the same item isn\'t added twice.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'What to buy, e.g. "Milk"'],
                'category' => ['type' => 'string', 'enum' => ItemCategory::values()],
                'quantity' => ['type' => 'string', 'description' => 'Optional, e.g. "2 l" or "6"'],
            ],
            'required' => ['name', 'category'],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:100',
                // Already on the list (not yet bought): say so instead of adding it twice
                Rule::unique('shopping_items', 'name')->where('household_id', $context->household->id)
                    ->where('checked', false)->whereNull('deleted_at')],
            'category' => ['required', Rule::enum(ItemCategory::class)],
            'quantity' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function authorize(ToolContext $context): bool
    {
        return Gate::forUser($context->user)->allows('create', [ShoppingItem::class, $context->household]);
    }

    public function describe(array $input, ToolContext $context): string
    {
        return 'Add "'.$input['name'].'"'.(! empty($input['quantity']) ? " ({$input['quantity']})" : '').' to the shopping list';
    }

    public function run(array $input, ToolContext $context): array
    {
        $item = $context->household->shoppingItems()->create([
            'name' => $input['name'],
            'category' => $input['category'],
            'quantity' => $input['quantity'] ?? null,
            'added_by' => $context->user->id,
            'source' => ShoppingSource::Manual,
        ]);

        return ['added' => ['id' => $item->id, 'name' => $item->name, 'quantity' => $item->quantity]];
    }
}
