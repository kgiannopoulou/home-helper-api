<?php

namespace App\Models;

use App\Enums\BudgetPeriod;
use App\Enums\ExpenseCategory;
use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A spending limit per week or month, for everything (category null) or one category.
 */
#[Fillable(['period', 'category', 'amount'])]
class Budget extends Model
{
    /** @use HasFactory<BudgetFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'period' => BudgetPeriod::class,
            'category' => ExpenseCategory::class,
            'amount' => 'decimal:2',
        ];
    }
}
