<?php

namespace App\Policies;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;

class HouseholdPolicy
{
    public function view(User $user, Household $household): bool
    {
        return $user->isMemberOf($household);
    }

    public function update(User $user, Household $household): bool
    {
        return $user->roleIn($household) === HouseholdRole::Owner;
    }

    public function invite(User $user, Household $household): bool
    {
        return $user->roleIn($household) === HouseholdRole::Owner;
    }
}
