<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One member's morning briefing for one day, as it was built and sent (App\Briefing).
 *
 * @property string $id
 * @property string $household_id
 * @property int $user_id
 * @property CarbonImmutable $date
 * @property array<string, mixed> $content
 * @property string $text
 * @property bool $pushed
 * @property CarbonImmutable $created_at
 */
#[Fillable(['user_id', 'date', 'content', 'text', 'pushed'])]
class Briefing extends Model
{
    use BelongsToHousehold, HasUlids;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'content' => 'array',
            'pushed' => 'boolean',
        ];
    }
}
