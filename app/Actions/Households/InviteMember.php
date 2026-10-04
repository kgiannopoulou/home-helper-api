<?php

namespace App\Actions\Households;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Invite;
use App\Models\User;
use App\Notifications\HouseholdInvite;
use Closure;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Emails someone a signed link to join a household. Inviting the same address
 * again sends a fresh link. Used by the API (phone) and the web dashboard.
 */
class InviteMember
{
    /** How long an invite link works */
    public const DAYS_VALID = 7;

    /**
     * @param  array<string, mixed>  $input  email, and optionally role
     */
    public function __invoke(Household $household, User $inviter, array $input): Invite
    {
        $data = Validator::make($input, [
            'email' => [
                'required', 'email', 'max:255',
                function (string $attribute, string $email, Closure $fail) use ($household) {
                    if ($household->users()->where('email', $email)->exists()) {
                        $fail('They are already a member of this household.');
                    }
                },
            ],
            'role' => ['sometimes', Rule::enum(HouseholdRole::class)],
        ])->validate();

        $invite = $household->invites()->updateOrCreate(
            ['email' => strtolower($data['email'])],
            [
                'role' => $data['role'] ?? HouseholdRole::Member,
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(self::DAYS_VALID),
                'accepted_at' => null,
            ],
        );

        $url = URL::temporarySignedRoute('api.invites.accept', $invite->expires_at, ['invite' => $invite->id]);
        Notification::route('mail', $invite->email)->notify(new HouseholdInvite($invite, $url));

        return $invite;
    }
}
