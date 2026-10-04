<?php

namespace App\Http\Controllers\Api;

use App\Actions\Households\InviteMember;
use App\Http\Controllers\Controller;
use App\Http\Resources\HouseholdResource;
use App\Http\Resources\InviteResource;
use App\Models\Household;
use App\Models\Invite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class InviteController extends Controller
{
    /**
     * Email someone a signed link to join the household. Inviting the same
     * address again sends a fresh link.
     */
    public function store(Request $request, Household $household, InviteMember $invite): JsonResponse
    {
        Gate::authorize('invite', $household);

        return (new InviteResource($invite($household, $request->user(), $request->all())))->response()->setStatusCode(201);
    }

    /**
     * Join the household from the signed link. The signature (checked by the
     * `signed` middleware) proves the link is ours, unchanged and not expired.
     */
    public function accept(Request $request, Invite $invite): HouseholdResource|JsonResponse
    {
        $user = $request->user();

        if ($invite->accepted_at) {
            return response()->json(['message' => 'This invite has already been used.'], 410);
        }
        if ($invite->expires_at->isPast()) {
            return response()->json(['message' => 'This invite has expired.'], 410);
        }
        if (strcasecmp($invite->email, $user->email) !== 0) {
            return response()->json(['message' => 'This invite was sent to a different email address.'], 403);
        }

        DB::transaction(function () use ($invite, $user) {
            if (! $user->isMemberOf($invite->household_id)) {
                $invite->household->addMember($user, $invite->role);
            }
            $invite->update(['accepted_at' => now()]);
        });

        return new HouseholdResource($user->households()->find($invite->household_id));
    }
}
