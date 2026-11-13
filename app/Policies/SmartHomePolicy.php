<?php

namespace App\Policies;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;

/**
 * Who may do what with the household's smart home (docs/smart-home.md#who-can-do-what):
 * members see the devices and use the everyday ones; the owner connects Home
 * Assistant, chooses which devices Home Helper imports, writes automation
 * rules and approves sensitive actions.
 */
class SmartHomePolicy
{
    public function view(User $user, Household $household): bool
    {
        return $user->isMemberOf($household);
    }

    /** Connect / disconnect Home Assistant, import devices, mark them sensitive, edit rules */
    public function manage(User $user, Household $household): bool
    {
        return $user->roleIn($household) === HouseholdRole::Owner;
    }

    /** Switch an everyday device; sensitive ones still need approve() */
    public function control(User $user, Household $household): bool
    {
        return $user->isMemberOf($household);
    }

    public function approve(User $user, Household $household): bool
    {
        return $user->roleIn($household) === HouseholdRole::Owner;
    }
}
