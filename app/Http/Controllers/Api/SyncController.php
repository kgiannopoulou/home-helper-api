<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Sync\Collections;
use App\Sync\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    /**
     * POST {since, changes: {expenses: [...], chores: [...], ...}}
     * → {since, changes, remapped, rejected}. See SyncService for the rules.
     */
    public function __invoke(Request $request, Household $household): JsonResponse
    {
        $data = $request->validate([
            'since' => ['present', 'nullable', 'date'],
            'changes' => ['present', 'array'],
            'changes.*' => ['array', 'max:1000'],
        ]);

        $unknown = array_diff(array_keys($data['changes']), array_keys(Collections::all()));
        if ($unknown) {
            return response()->json([
                'message' => 'Unknown collection: '.implode(', ', $unknown),
                'errors' => ['changes' => ['Unknown collection: '.implode(', ', $unknown)]],
            ], 422);
        }

        return response()->json(
            (new SyncService($household, $request->user()))->sync($data['since'], $data['changes']),
        );
    }
}
