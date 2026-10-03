<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chore_completions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('chore_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('done_at', 3);
            // Minutes it actually took, used for fair share between members
            $table->unsignedSmallInteger('minutes');
            $table->timestamps(3);
            $table->softDeletes(precision: 3);

            $table->index(['chore_id', 'done_at']);
            $table->index(['household_id', 'done_at']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE chore_completions ADD CONSTRAINT chore_completions_minutes_range CHECK (minutes BETWEEN 1 AND 600)');
    }

    public function down(): void
    {
        Schema::dropIfExists('chore_completions');
    }
};
