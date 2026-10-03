<?php

namespace App\Models;

use App\Enums\HouseholdRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A user's place in a household (the household_user pivot row).
 *
 * @property int $id
 * @property string $household_id
 * @property int $user_id
 * @property HouseholdRole $role
 */
class Membership extends Pivot
{
    protected $table = 'household_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'role' => HouseholdRole::class,
        ];
    }
}
