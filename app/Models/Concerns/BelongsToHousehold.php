<?php

namespace App\Models\Concerns;

use App\Models\Household;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every table with a household_id: each row is owned by one household.
 */
trait BelongsToHousehold
{
    /**
     * Timestamps keep their milliseconds: last write wins compares them (Phase 4 sync).
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeForHousehold(Builder $query, Household|string $household): void
    {
        $query->where($this->qualifyColumn('household_id'), $household instanceof Household ? $household->getKey() : $household);
    }
}
