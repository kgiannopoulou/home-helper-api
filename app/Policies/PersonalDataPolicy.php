<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Food, water, sleep, workouts and weight: only the person who logged them,
 * even inside the same household.
 */
abstract class PersonalDataPolicy extends HouseholdDataPolicy
{
    public function view(User $user, Model $row): bool
    {
        return $row->user_id === $user->id && parent::view($user, $row);
    }
}
