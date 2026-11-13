<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One meal in the plan (App\Kitchen\Planning, docs/kitchen.md#meal-plan).
 *
 * @property string $id
 * @property string $household_id
 * @property CarbonImmutable $date
 * @property string $meal breakfast|lunch|dinner
 * @property string|null $recipe_id
 * @property string|null $title instead of a recipe: "Eating out"
 * @property int $servings
 * @property bool $auto chosen by "plan the week", not a person
 * @property CarbonImmutable|null $cooked_at
 * @property int|null $created_by
 * @property-read Recipe|null $recipe
 */
class MealPlanEntry extends Model
{
    use BelongsToHousehold, HasUlids;

    public const MEALS = ['breakfast', 'lunch', 'dinner'];

    protected $guarded = ['id', 'household_id'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'servings' => 'integer',
            'auto' => 'boolean',
            'cooked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function label(): string
    {
        return $this->recipe->name ?? (string) $this->title;
    }
}
