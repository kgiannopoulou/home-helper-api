<?php

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('recurring_bill_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->decimal('amount', 10, 2);
            $table->enum('category', ExpenseCategory::values());
            $table->string('note')->nullable();
            $table->enum('source', ExpenseSource::values())->default(ExpenseSource::Manual->value);
            $table->timestamps(3);
            $table->softDeletes(precision: 3);

            $table->index(['household_id', 'date']);
            $table->index(['household_id', 'category', 'date']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
