<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sleep_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The morning you woke up
            $table->date('date');
            $table->timestamp('bed_at', 3);
            $table->timestamp('woke_at', 3);
            // Worked out by MySQL, so it can never disagree with the two times
            $table->decimal('hours', 4, 2)->storedAs('timestampdiff(minute, bed_at, woke_at) / 60');
            $table->unsignedTinyInteger('quality');
            $table->timestamps(3);
            $table->softDeletes(precision: 3);
            // 1 while the row is live, NULL once soft-deleted. Unique keys include it, so a
            // deleted row doesn't block adding the same name again (NULLs never clash).
            $table->boolean('alive')->nullable()->storedAs('if(deleted_at is null, 1, null)');

            $table->unique(['user_id', 'date', 'alive']);
            $table->index(['household_id', 'date']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE sleep_entries
            ADD CONSTRAINT sleep_entries_woke_after_bed CHECK (woke_at > bed_at),
            ADD CONSTRAINT sleep_entries_quality_range CHECK (quality BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sleep_entries');
    }
};
