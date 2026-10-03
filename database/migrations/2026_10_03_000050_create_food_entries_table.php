<?php

use App\Enums\FoodSource;
use App\Enums\Meal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            // Food, water, sleep and workouts belong to one person in the household
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The local calendar day, kept apart from eaten_at so time zones don't move meals
            $table->date('date');
            $table->timestamp('eaten_at', 3);
            $table->string('name', 100);
            $table->enum('meal', Meal::values());
            $table->unsignedSmallInteger('grams')->nullable();
            $table->unsignedSmallInteger('kcal');
            $table->decimal('protein', 5, 1)->default(0);
            $table->decimal('carbs', 5, 1)->default(0);
            $table->decimal('fat', 5, 1)->default(0);
            $table->decimal('fiber', 5, 1)->default(0);
            $table->enum('source', FoodSource::values())->default(FoodSource::Database->value);
            $table->timestamps(3);
            $table->softDeletes(precision: 3);

            $table->index(['household_id', 'date']);
            $table->index(['user_id', 'date']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE food_entries
            ADD CONSTRAINT food_entries_kcal_range CHECK (kcal <= 5000),
            ADD CONSTRAINT food_entries_macros_not_negative CHECK (protein >= 0 AND carbs >= 0 AND fat >= 0 AND fiber >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('food_entries');
    }
};
