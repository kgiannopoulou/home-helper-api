<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A room, or a personal routine area, that chores belong to.
 */
#[Fillable(['name', 'emoji', 'personal'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'personal' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Chore, $this>
     */
    public function chores(): HasMany
    {
        return $this->hasMany(Chore::class);
    }
}
