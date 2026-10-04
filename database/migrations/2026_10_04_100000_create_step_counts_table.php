<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Steps per person per day (the phone's pedometer). One row per day, so its id is
     * made from the person and the day (StepCount::idFor): every phone of that person
     * makes the same id for the same day without asking the server.
     */
    public function up(): void
    {
        Schema::create('step_counts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('steps');
            $table->timestamps(3);
            $table->timestamp('synced_at', 3)->useCurrent()->useCurrentOnUpdate();
            $table->softDeletes(precision: 3);
            // 1 while the row is live, NULL once soft-deleted. Unique keys include it, so a
            // deleted row doesn't block adding the same day again (NULLs never clash).
            $table->boolean('alive')->nullable()->storedAs('if(deleted_at is null, 1, null)');

            $table->unique(['user_id', 'date', 'alive']);
            $table->index(['household_id', 'date']);
            $table->index(['household_id', 'synced_at']);
        });

        DB::statement('ALTER TABLE step_counts ADD CONSTRAINT step_counts_steps_range CHECK (steps <= 200000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('step_counts');
    }
};
