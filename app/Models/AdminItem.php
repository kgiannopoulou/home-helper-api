<?php

namespace App\Models;

use App\Enums\AdminKind;
use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Database\Factories\AdminItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Life admin: a bill, appointment or renewal with a due day.
 *
 * @property string $id
 * @property string $household_id
 * @property string $title
 * @property AdminKind $kind
 * @property CarbonImmutable|null $due_on
 * @property int $repeat_months
 * @property int|null $remind_days
 * @property string|null $amount
 * @property CarbonImmutable|null $done_at
 */
#[Fillable(['title', 'kind', 'due_on', 'repeat_months', 'remind_days', 'amount', 'done_at'])]
class AdminItem extends Model
{
    /** @use HasFactory<AdminItemFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'kind' => AdminKind::class,
            'due_on' => 'date',
            'repeat_months' => 'integer',
            'remind_days' => 'integer',
            'amount' => 'decimal:2',
            'done_at' => 'datetime',
        ];
    }
}
