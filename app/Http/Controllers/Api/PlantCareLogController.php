<?php

namespace App\Http\Controllers\Api;

use App\Enums\PlantCareType;
use App\Http\Requests\Api\PlantCareLogRequest;
use App\Http\Requests\Api\RecordPlantCareRequest;
use App\Http\Resources\PlantCareLogResource;
use App\Http\Resources\PlantResource;
use App\Models\Household;
use App\Models\Plant;
use App\Models\PlantCareLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Care logs as a module (list, fix or delete a log, and what sync uses), plus
 * one action per kind of care: POST …/plants/{plant}/water {amount_ml}.
 * Either way the plant's last and next dates follow (PlantCareLog::booted).
 */
class PlantCareLogController extends HouseholdDataController
{
    protected string $model = PlantCareLog::class;

    protected string $resource = PlantCareLogResource::class;

    protected string $request = PlantCareLogRequest::class;

    protected ?string $dateColumn = 'performed_at';

    protected ?string $creatorColumn = 'user_id';

    /**
     * Record one care action. The type comes from the route (water, fertilize,
     * move…); watering without an amount logs the plant's usual amount. Moving
     * can change where the plant is, which takes it out of (or into) the weather.
     */
    public function record(RecordPlantCareRequest $request, Household $household, string $plant): JsonResponse
    {
        $plant = $this->plant($household, $plant);
        Gate::authorize('create', [PlantCareLog::class, $household]);

        $type = PlantCareType::from($request->route('type'));
        $data = $request->validated();

        $log = DB::transaction(function () use ($household, $plant, $type, $data) {
            if ($type === PlantCareType::Moved) {
                $plant->update(Arr::only($data, ['location_type', 'room_id']));
            }

            return $household->plantCareLogs()->create([
                'plant_id' => $plant->id,
                'user_id' => auth()->id(),
                'type' => $type,
                'amount_ml' => $data['amount_ml'] ?? ($type === PlantCareType::Watered ? $plant->water_amount_ml : null),
                'notes' => $data['notes'] ?? null,
                'performed_at' => $data['performed_at'] ?? now(),
            ]);
        });

        return (new PlantCareLogResource($log->refresh()))
            ->additional(['plant' => new PlantResource($plant->refresh())])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * One plant's timeline, newest first: waterings, fertilizer, health notes.
     */
    public function history(Request $request, Household $household, string $plant): AnonymousResourceCollection
    {
        $plant = $this->plant($household, $plant);

        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(PlantCareType::class)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return PlantCareLogResource::collection($plant->careLogs()
            ->when(isset($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->orderByDesc('performed_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString());
    }

    private function plant(Household $household, string $id): Plant
    {
        $plant = $household->plants()->findOrFail($id);
        Gate::authorize('view', $plant);

        return $plant;
    }
}
