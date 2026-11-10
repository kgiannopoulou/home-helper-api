<?php

namespace App\Ai\Tools\Shopping;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\InventoryItem;
use App\Queries\RunOutQuery;

class GetKitchenInventory extends Tool
{
    public function name(): string
    {
        return 'get_kitchen_inventory';
    }

    public function label(): string
    {
        return 'Checked the kitchen';
    }

    public function description(): string
    {
        return 'The kitchen: items that are low or empty, items expiring within the next N days (default 7), and when staples will run out based on how often they are bought. Use it for "what\'s running low", "what expires soon", "do we have X".';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'expiring_within_days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 60, 'description' => 'Default 7'],
                'search' => ['type' => 'string', 'description' => 'Only items whose name contains this'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'expiring_within_days' => ['sometimes', 'integer', 'between:0,60'],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }

    public function run(array $input, ToolContext $context): array
    {
        $h = $context->household;
        $items = fn () => $h->inventoryItems()->getQuery()
            ->when($input['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.addcslashes($s, '%_\\').'%'));
        $row = fn (InventoryItem $i) => [
            'name' => $i->name,
            'level' => $i->level?->value,
            'quantity' => $i->quantity,
            'location' => $i->location?->value,
            'expires_on' => $i->expires_on?->toDateString(),
        ];

        $until = $context->today->addDays($input['expiring_within_days'] ?? 7)->toDateString();

        return [
            'low_or_empty' => $items()->whereIn('level', ['low', 'empty'])->orderBy('name')->limit(50)->get()->map($row)->all(),
            'expiring' => $items()->whereNotNull('expires_on')->where('expires_on', '<=', $until)->orderBy('expires_on')->limit(50)->get()->map($row)->all(),
            'matching' => isset($input['search']) ? $items()->orderBy('name')->limit(20)->get()->map($row)->all() : null,
            'running_out' => array_slice((new RunOutQuery($h, $context->today))->get(), 0, 15),
        ];
    }
}
