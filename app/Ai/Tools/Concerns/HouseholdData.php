<?php

namespace App\Ai\Tools\Concerns;

use App\Ai\Tools\ToolContext;
use App\Support\HomeTime;
use Carbon\CarbonInterface;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Small helpers the tools share: ids that must belong to this household,
 * money and times the way people read them.
 */
trait HouseholdData
{
    /** A live row of this household, by id */
    protected function idIn(string $table, ToolContext $context): Exists
    {
        return Rule::exists($table, 'id')
            ->where('household_id', $context->household->id)
            ->whereNull('deleted_at');
    }

    protected function money(float|string|null $amount): ?float
    {
        return $amount === null ? null : round((float) $amount, 2);
    }

    /** "2026-11-10 18:30", at home */
    protected function homeTime(?CarbonInterface $at): ?string
    {
        return $at?->setTimezone(HomeTime::zone())->format('Y-m-d H:i');
    }

    /** @return array<string, string> */
    protected function idSchema(string $what): array
    {
        return ['type' => 'string', 'description' => "The {$what}'s id, from an earlier tool result"];
    }
}
