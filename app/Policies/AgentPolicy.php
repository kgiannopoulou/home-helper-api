<?php

namespace App\Policies;

use App\Enums\HouseholdRole;
use App\Models\Agent;
use App\Models\Household;
use App\Models\User;

/**
 * Who may do what with the household's computers (agent/docs/design.md#trust-model):
 * members see them and run non-sensitive commands; the owner pairs, renames
 * and removes them, confirms sensitive commands and installs updates.
 */
class AgentPolicy
{
    public function viewAny(User $user, Household $household): bool
    {
        return $user->isMemberOf($household);
    }

    public function view(User $user, Agent $agent): bool
    {
        return $user->isMemberOf($agent->household_id);
    }

    public function pair(User $user, Household $household): bool
    {
        return $user->roleIn($household) === HouseholdRole::Owner;
    }

    /** Rename, revoke, install an update */
    public function manage(User $user, Agent $agent): bool
    {
        return $user->roleIn($agent->household_id) === HouseholdRole::Owner;
    }

    /** Ask for a command on its allowlist; sensitive ones still need confirm() */
    public function run(User $user, Agent $agent): bool
    {
        return ! $agent->isRevoked() && $user->isMemberOf($agent->household_id);
    }

    public function confirm(User $user, Agent $agent): bool
    {
        return ! $agent->isRevoked() && $user->roleIn($agent->household_id) === HouseholdRole::Owner;
    }
}
