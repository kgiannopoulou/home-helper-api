<?php

namespace App\Ai\Tools\Plants;

use App\Ai\Tools\Concerns\HouseholdData;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\PlantAlert;

class ListPlantAlerts extends Tool
{
    use HouseholdData;

    public function name(): string
    {
        return 'list_plant_alerts';
    }

    public function label(): string
    {
        return 'Read the plant alerts';
    }

    public function description(): string
    {
        return 'Plant alerts the hourly plant check raised and nobody has dealt with yet (watering overdue, frost, move inside…), most severe first.';
    }

    public function run(array $input, ToolContext $context): array
    {
        $alerts = $context->household->plantAlerts()->with('plant')->whereNull('resolved_at')
            ->orderByDesc('triggered_at')->limit(30)->get()
            ->sortByDesc(fn (PlantAlert $a) => $a->severity->rank())
            ->values()
            ->map(fn (PlantAlert $a) => [
                'plant' => $a->plant?->label(),
                'type' => $a->type->value,
                'severity' => $a->severity->value,
                'title' => $a->title,
                'message' => $a->message,
                'since' => $this->homeTime($a->triggered_at),
            ])->all();

        return ['open_alerts' => $alerts];
    }
}
