<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recipe: Home Helper's own (household_id null, database/data/recipes.php)
 * or a household's (docs/kitchen.md#recipes).
 *
 * @property string $id
 * @property string|null $household_id
 * @property string|null $slug
 * @property string $name
 * @property int $servings
 * @property int|null $minutes
 * @property list<string> $tags
 * @property string $steps
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read Collection<int, RecipeIngredient> $ingredients
 */
class Recipe extends Model
{
    use HasUlids;

    protected $guarded = ['id', 'household_id'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'servings' => 'integer',
            'minutes' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<RecipeIngredient, $this> */
    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('id');
    }

    /**
     * Home Helper's own and this household's
     *
     * @param  Builder<static>  $query
     */
    public function scopeFor(Builder $query, Household $household): void
    {
        $query->where(fn ($q) => $q->whereNull('household_id')->orWhere('household_id', $household->id));
    }
}
