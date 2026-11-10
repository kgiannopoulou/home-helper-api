<?php

namespace App\Ai\Tools\Household;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\User;

class GetHouseholdOverview extends Tool
{
    public function name(): string
    {
        return 'get_household_overview';
    }

    public function label(): string
    {
        return 'Looked at the household';
    }

    public function description(): string
    {
        return 'The household: its name, currency, members and their roles, whether its location is set (needed for weather), and how many rooms, plants, chores, open to-dos and shopping items it has. Use it for questions about the household itself or who is in it.';
    }

    public function run(array $input, ToolContext $context): array
    {
        $h = $context->household;

        return [
            'name' => $h->name,
            'currency' => $h->currency,
            'location_set' => $h->hasLocation(),
            'members' => $h->users()->orderBy('name')->get()->map(fn (User $u) => [
                'name' => $u->name,
                'role' => $u->membership->role->value,
                'is_you' => $u->id === $context->user->id,
            ])->all(),
            'counts' => [
                'rooms' => $h->rooms()->count(),
                'plants' => $h->plants()->count(),
                'chores' => $h->chores()->count(),
                'open_todos' => $h->todos()->whereNull('done_at')->count(),
                'shopping_items_to_buy' => $h->shoppingItems()->where('checked', false)->count(),
                'kitchen_items' => $h->inventoryItems()->count(),
                'recurring_bills' => $h->recurringBills()->where('active', true)->count(),
            ],
        ];
    }
}
