<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weights', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('kg', 5, 2);
            $table->timestamps(3);
            $table->softDeletes(precision: 3);
            // 1 while the row is live, NULL once soft-deleted. Unique keys include it, so a
            // deleted row doesn't block adding the same name again (NULLs never clash).
            $table->boolean('alive')->nullable()->storedAs('if(deleted_at is null, 1, null)');

            $table->unique(['user_id', 'date', 'alive']);
            $table->index(['household_id', 'date']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE weights ADD CONSTRAINT weights_kg_range CHECK (kg BETWEEN 20 AND 400)');
    }

    public function down(): void
    {
        Schema::dropIfExists('weights');
    }
};
