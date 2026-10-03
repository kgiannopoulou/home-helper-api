<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A repeating task in a room. When it was last done is not stored here:
     * it is the latest row in chore_completions.
     */
    public function up(): void
    {
        Schema::create('chores', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('every_days');
            $table->unsignedSmallInteger('minutes');
            // Set when the app changed every_days from your habits
            $table->unsignedSmallInteger('learned_from_days')->nullable();
            $table->date('learned_on')->nullable();
            // You undid a learned change, so the frequency stays as you set it
            $table->boolean('fixed_frequency')->default(false);
            $table->timestamps(3);
            $table->softDeletes(precision: 3);
            // 1 while the row is live, NULL once soft-deleted. Unique keys include it, so a
            // deleted row doesn't block adding the same name again (NULLs never clash).
            $table->boolean('alive')->nullable()->storedAs('if(deleted_at is null, 1, null)');

            $table->unique(['room_id', 'name', 'alive']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE chores
            ADD CONSTRAINT chores_every_days_range CHECK (every_days BETWEEN 1 AND 365),
            ADD CONSTRAINT chores_minutes_range CHECK (minutes BETWEEN 1 AND 600)');
    }

    public function down(): void
    {
        Schema::dropIfExists('chores');
    }
};
