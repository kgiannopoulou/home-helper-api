<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\RecurringBillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A fixed monthly cost (rent, phone…), added as an expense each month.
 */
#[Fillable(['name', 'amount', 'category', 'day_of_month', 'active'])]
class RecurringBill extends Model
{
    /** @use HasFactory<RecurringBillFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'category' => ExpenseCategory::class,
            'day_of_month' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
