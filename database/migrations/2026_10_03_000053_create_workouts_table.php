<?php

use App\Enums\Intensity;
use App\Enums\WorkoutSource;
use App\Enums\WorkoutType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('type', WorkoutType::values());
            $table->unsignedSmallInteger('minutes');
            $table->enum('intensity', Intensity::values())->default(Intensity::Moderate->value);
            $table->unsignedSmallInteger('kcal');
            $table->enum('source', WorkoutSource::values())->default(WorkoutSource::Manual->value);
            $table->text('notes')->nullable();
            $table->timestamps(3);
            $table->softDeletes(precision: 3);

            $table->index(['household_id', 'date']);
            $table->index(['user_id', 'date']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE workouts ADD CONSTRAINT workouts_minutes_range CHECK (minutes BETWEEN 1 AND 720)');
    }

    public function down(): void
    {
        Schema::dropIfExists('workouts');
    }
};
