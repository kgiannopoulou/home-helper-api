<?php

namespace App\Http\Controllers\Api;

use App\Enums\PlantAlertType;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlantAlertResource;
use App\Models\Household;
use App\Models\PlantAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * What CheckPlantCare raised. Alerts are written by the server; a member can
 * only read them and dismiss one.
 */
class PlantAlertController extends Controller
{
    /**
     * Open alerts by default, most recent first. ?status=resolved|all for history.
     */
    public function index(Request $request, Household $household): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [PlantAlert::class, $household]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'resolved', 'all'])],
            'plant_id' => ['nullable', 'string'],
            'type' => ['nullable', Rule::enum(PlantAlertType::class)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $status = $filters['status'] ?? 'open';

        return PlantAlertResource::collection($household->plantAlerts()
            // Not the alerts of a deleted plant
            ->whereHas('plant')
            ->when($status === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($status === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->when(isset($filters['plant_id']), fn ($q) => $q->where('plant_id', $filters['plant_id']))
            ->when(isset($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->orderByDesc('triggered_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString());
    }

    /**
     * Dismiss an alert ("I've seen it"). Resolving one that is already resolved
     * changes nothing.
     */
    public function resolve(Household $household, string $plantAlert): PlantAlertResource
    {
        $alert = $household->plantAlerts()->findOrFail($plantAlert);
        Gate::authorize('update', $alert);

        if ($alert->resolved_at === null) {
            $alert->update([
                'resolved_at' => now(),
                'metadata' => [...($alert->metadata ?? []), 'dismissed_by' => auth()->id()],
            ]);
        }

        return new PlantAlertResource($alert);
    }
}
