<?php

namespace App\Models;

use App\Enums\ItemCategory;
use App\Enums\SupplyLevel;
use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\SupplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A cleaning or bathroom supply and how much is left.
 */
#[Fillable(['name', 'category', 'level'])]
class Supply extends Model
{
    /** @use HasFactory<SupplyFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'category' => ItemCategory::class,
            'level' => SupplyLevel::class,
        ];
    }
}
