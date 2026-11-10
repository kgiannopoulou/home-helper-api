<?php

namespace App\Ai\Tools\Plants;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\Plant;
use App\Plants\PlantCareService;

/**
 * "Which plants need attention?": the same answer as GET …/plant-care/today,
 * with the plant ids log_plant_care needs.
 */
class GetPlantCareToday extends Tool
{
    public function __construct(private PlantCareService $care) {}

    public function name(): string
    {
        return 'get_plant_care_today';
    }

    public function label(): string
    {
        return 'Checked the plants';
    }

    public function description(): string
    {
        return 'Which plants need attention: those to water today (with how much, how many days overdue, and whether rain will do it for outdoor plants), other care that is due (fertilizer, repotting), weather warnings for plants outside (frost, heat, strong wind, heavy rain), and care coming up this week. Each plant has a plant_id for log_plant_care.';
    }

    public function run(array $input, ToolContext $context): array
    {
        $today = $this->care->today($context->household);
        $today['plants'] = $context->household->plants()->orderBy('name')->get()
            ->map(fn (Plant $p) => ['plant_id' => $p->id, 'name' => $p->label(), 'where' => $p->location_type->value])
            ->all();

        return $today;
    }
}
