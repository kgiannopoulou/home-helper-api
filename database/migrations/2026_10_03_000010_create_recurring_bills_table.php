<?php

use App\Enums\ExpenseCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_bills', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('amount', 10, 2);
            $table->enum('category', ExpenseCategory::values());
            $table->unsignedTinyInteger('day_of_month');
            $table->boolean('active')->default(true);
            $table->timestamps(3);
            $table->softDeletes(precision: 3);
            // 1 while the row is live, NULL once soft-deleted. Unique keys include it, so a
            // deleted row doesn't block adding the same name again (NULLs never clash).
            $table->boolean('alive')->nullable()->storedAs('if(deleted_at is null, 1, null)');

            $table->unique(['household_id', 'name', 'alive']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE recurring_bills
            ADD CONSTRAINT recurring_bills_amount_positive CHECK (amount > 0),
            ADD CONSTRAINT recurring_bills_day_of_month_range CHECK (day_of_month BETWEEN 1 AND 28)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_bills');
    }
};
