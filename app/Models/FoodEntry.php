<?php

namespace App\Models;

use App\Enums\FoodSource;
use App\Enums\Meal;
use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\FoodEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something one person ate, with its nutrients.
 */
#[Fillable(['user_id', 'date', 'eaten_at', 'name', 'meal', 'grams', 'kcal', 'protein', 'carbs', 'fat', 'fiber', 'source'])]
class FoodEntry extends Model
{
    /** @use HasFactory<FoodEntryFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'eaten_at' => 'datetime',
            'meal' => Meal::class,
            'grams' => 'integer',
            'kcal' => 'integer',
            'protein' => 'decimal:1',
            'carbs' => 'decimal:1',
            'fat' => 'decimal:1',
            'fiber' => 'decimal:1',
            'source' => FoodSource::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
