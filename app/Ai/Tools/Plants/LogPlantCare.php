<?php

namespace App\Ai\Tools\Plants;

use App\Ai\Tools\Concerns\HouseholdData;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Enums\PlantCareType;
use App\Models\PlantCareLog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * "I watered the monstera": the same as POST …/plants/{plant}/water, so the
 * plant's next dates and its alerts update the same way.
 */
class LogPlantCare extends Tool
{
    use HouseholdData;

    /** Care that needs no extra fields (moving a plant does: use the app for that) */
    private const TYPES = [PlantCareType::Watered, PlantCareType::Fertilized, PlantCareType::Pruned, PlantCareType::Repotted, PlantCareType::Misted, PlantCareType::Inspected];

    public function name(): string
    {
        return 'log_plant_care';
    }

    public function label(): string
    {
        return 'Log plant care';
    }

    public function writes(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Record care just given to a plant (watered, fertilized, pruned, repotted, misted, inspected), which moves its next due date. Takes the plant_id from get_plant_care_today.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plant_id' => $this->idSchema('plant'),
                'type' => ['type' => 'string', 'enum' => array_map(fn ($t) => $t->value, self::TYPES)],
                'amount_ml' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20000, 'description' => 'For watering; default the plant\'s usual amount'],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['plant_id', 'type'],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'plant_id' => ['required', 'string', $this->idIn('plants', $context)],
            'type' => ['required', Rule::in(array_map(fn ($t) => $t->value, self::TYPES))],
            'amount_ml' => ['nullable', 'integer', 'between:1,20000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function authorize(ToolContext $context): bool
    {
        return Gate::forUser($context->user)->allows('create', [PlantCareLog::class, $context->household]);
    }

    public function describe(array $input, ToolContext $context): string
    {
        $plant = $context->household->plants()->findOrFail((string) $input['plant_id']);

        return 'Log that '.$plant->label().' was '.$input['type'].' now'.(! empty($input['amount_ml']) ? " ({$input['amount_ml']} ml)" : '');
    }

    public function run(array $input, ToolContext $context): array
    {
        $plant = $context->household->plants()->findOrFail((string) $input['plant_id']);
        $type = PlantCareType::from($input['type']);

        $log = $context->household->plantCareLogs()->create([
            'plant_id' => $plant->id,
            'user_id' => $context->user->id,
            'type' => $type,
            'amount_ml' => $input['amount_ml'] ?? ($type === PlantCareType::Watered ? $plant->water_amount_ml : null),
            'notes' => $input['notes'] ?? null,
            'performed_at' => now(),
        ]);

        $plant->refresh();

        return [
            'logged' => ['plant' => $plant->label(), 'type' => $type->value, 'amount_ml' => $log->amount_ml],
            'next_watering' => $this->homeTime($plant->next_watering_at),
        ];
    }
}
