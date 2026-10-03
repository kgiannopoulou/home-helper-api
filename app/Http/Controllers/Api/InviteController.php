<?php

namespace App\Http\Controllers\Api;

use App\Enums\HouseholdRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\HouseholdResource;
use App\Http\Resources\InviteResource;
use App\Models\Household;
use App\Models\Invite;
use App\Notifications\HouseholdInvite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

class InviteController extends Controller
{
    /** How long an invite link works */
    public const DAYS_VALID = 7;

    /**
     * Email someone a signed link to join the household. Inviting the same
     * address again sends a fresh link.
     */
    public function store(Request $request, Household $household): JsonResponse
    {
        Gate::authorize('invite', $household);

        $data = $request->validate([
            'email' => [
                'required', 'email', 'max:255',
                function (string $attribute, string $email, \Closure $fail) use ($household) {
                    if ($household->users()->where('email', $email)->exists()) {
                        $fail('They are already a member of this household.');
                    }
                },
            ],
            'role' => ['sometimes', Rule::enum(HouseholdRole::class)],
        ]);

        $invite = $household->invites()->updateOrCreate(
            ['email' => strtolower($data['email'])],
            [
                'role' => $data['role'] ?? HouseholdRole::Member,
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(self::DAYS_VALID),
                'accepted_at' => null,
            ],
        );

        $url = URL::temporarySignedRoute('api.invites.accept', $invite->expires_at, ['invite' => $invite->id]);
        Notification::route('mail', $invite->email)->notify(new HouseholdInvite($invite, $url));

        return (new InviteResource($invite))->response()->setStatusCode(201);
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
