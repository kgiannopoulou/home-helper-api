<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared household data: any member may read and change it, nobody else may.
 */
abstract class HouseholdDataPolicy
{
    public function viewAny(User $user, Household $household): bool
    {
        return $user->isMemberOf($household);
    }

    public function create(User $user, Household $household): bool
    {
        return $user->isMemberOf($household);
    }

    public function view(User $user, Model $row): bool
    {
        return $user->isMemberOf($row->household_id);
    }

    public function update(User $user, Model $row): bool
    {
        return $this->view($user, $row);
    }

    public function delete(User $user, Model $row): bool
    {
        return $this->view($user, $row);
    }
}
