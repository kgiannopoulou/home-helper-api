<?php

namespace App\Models;

use App\Kitchen\Units;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ingredient of a recipe: an App\Kitchen\Ingredients key and an amount
 * in a base unit, for the recipe's servings.
 *
 * @property int $id
 * @property string $recipe_id
 * @property string $ingredient
 * @property float|null $amount
 * @property string|null $unit
 * @property bool $optional doesn't count against the recipe
 * @property bool $staple salt, oil...: assumed to be in the kitchen
 */
class RecipeIngredient extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'optional' => 'boolean', 'staple' => 'boolean'];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** "400 g tomato", "2 egg" */
    public function label(float $scale = 1.0): string
    {
        return trim(Units::format($this->amount !== null ? $this->amount * $scale : null, $this->unit).' '.$this->ingredient);
    }
}
