<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * How one week or month went, written by the scheduler (App\Memory\HouseholdDigest)
 * and kept in long-term memory, so "how did October go?" has an answer.
 *
 * @property string $id
 * @property string $household_id
 * @property string $period week | month
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property array<string, mixed> $stats
 * @property string $text
 */
#[Fillable(['period', 'starts_on', 'ends_on', 'stats', 'text'])]
class HouseholdSummary extends Model
{
    use BelongsToHousehold, HasUlids;

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'stats' => 'array',
        ];
    }

    /** "Week of Mon 3 Nov 2026", "October 2026" */
    public function label(): string
    {
        return $this->period === 'month'
            ? $this->starts_on->format('F Y')
            : 'Week of '.$this->starts_on->format('D j M Y');
    }
}
