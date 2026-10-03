<?php

use App\Enums\BudgetPeriod;
use App\Enums\ExpenseCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->enum('period', BudgetPeriod::values());
            // NULL = the whole budget for the period, otherwise one category
            $table->enum('category', ExpenseCategory::values())->nullable();
            // MySQL lets many NULLs into a unique key, so the key uses 'all' instead
            $table->string('category_key', 20)->storedAs("coalesce(category, 'all')");
            $table->decimal('amount', 10, 2);
            $table->timestamps(3);
            $table->softDeletes(precision: 3);
            // 1 while the row is live, NULL once soft-deleted. Unique keys include it, so a
            // deleted row doesn't block adding the same name again (NULLs never clash).
            $table->boolean('alive')->nullable()->storedAs('if(deleted_at is null, 1, null)');

            $table->unique(['household_id', 'period', 'category_key', 'alive']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE budgets ADD CONSTRAINT budgets_amount_not_negative CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
