<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseSource;
use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money spent on one day.
 */
#[Fillable(['user_id', 'recurring_bill_id', 'date', 'amount', 'category', 'note', 'source'])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
            'category' => ExpenseCategory::class,
            'source' => ExpenseSource::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<RecurringBill, $this>
     */
    public function recurringBill(): BelongsTo
    {
        return $this->belongsTo(RecurringBill::class);
    }
}
