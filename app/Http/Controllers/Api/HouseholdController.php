<?php

namespace App\Http\Controllers\Api;

use App\Enums\HouseholdRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\HouseholdResource;
use App\Http\Resources\MemberResource;
use App\Models\Household;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class HouseholdController extends Controller
{
    /**
     * The households the caller belongs to, with their role in each.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return HouseholdResource::collection($request->user()->households()->orderBy('name')->get());
    }

    /**
     * Start a new household; the caller becomes its owner.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
        ]);

        $household = DB::transaction(function () use ($data, $request) {
            $household = Household::create([...$data, 'currency' => strtoupper($data['currency'] ?? 'EUR')]);
            $household->addMember($request->user(), HouseholdRole::Owner);

            return $household;
        });

        return (new HouseholdResource($request->user()->households()->find($household->id)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Household $household): HouseholdResource
    {
        Gate::authorize('view', $household);

        return new HouseholdResource($request->user()->households()->find($household->id));
    }

    public function members(Household $household): AnonymousResourceCollection
    {
        Gate::authorize('view', $household);

        return MemberResource::collection($household->users()->orderBy('name')->get());
    }
}
