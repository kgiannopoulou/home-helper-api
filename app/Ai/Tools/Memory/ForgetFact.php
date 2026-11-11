<?php

namespace App\Ai\Tools\Memory;

use App\Ai\Tools\Concerns\HouseholdData;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\HouseholdFact;
use Illuminate\Validation\Rule;

class ForgetFact extends Tool
{
    use HouseholdData;

    public function name(): string
    {
        return 'forget_fact';
    }

    public function label(): string
    {
        return 'Forget something';
    }

    public function writes(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Forget one remembered fact, by its id from the context, when the person asks or says it is no longer true. To change a fact, forget the old one and remember the new one.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['fact_id' => $this->idSchema('fact')],
            'required' => ['fact_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return ['fact_id' => ['required', 'string', Rule::exists('household_facts', 'id')
            ->where('household_id', $context->household->id)
            ->where(fn ($q) => $q->where('private', false)->orWhere('user_id', $context->user->id))]];
    }

    public function describe(array $input, ToolContext $context): string
    {
        return 'Forget: "'.HouseholdFact::query()->whereKey($input['fact_id'])->value('content').'"';
    }

    public function run(array $input, ToolContext $context): array
    {
        $fact = HouseholdFact::query()->visibleTo($context->household, $context->user)->whereKey($input['fact_id'])->firstOrFail();
        $fact->delete();

        return ['forgotten' => $fact->content];
    }
}
