<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7, day 90 (docs/kitchen.md#meal-plan): what the household will eat,
 * one row per day and meal. A recipe (Home Helper's or the household's own)
 * or just a title ("Eating out", "Leftovers"), for a number of people.
 *
 * shopping_items.source gains `meal_plan`: the list the plan keeps up to date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plan_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('meal', ['breakfast', 'lunch', 'dinner']);
            $table->foreignUlid('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 120)->nullable();
            $table->unsignedTinyInteger('servings');
            $table->boolean('auto')->default(false);
            $table->timestamp('cooked_at', 3)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(3);

            $table->unique(['household_id', 'date', 'meal']);
        });
        // "A recipe or a title" is checked by App\Kitchen\Planning\MealPlan, not here: MySQL refuses a
        // CHECK on recipe_id because its foreign key sets it to NULL when the recipe is deleted (error 3823)
        DB::statement('ALTER TABLE meal_plan_entries ADD CONSTRAINT meal_plan_entries_servings_range CHECK (servings BETWEEN 1 AND 50)');

        DB::statement("ALTER TABLE shopping_items MODIFY source ENUM('manual', 'inventory', 'recipe', 'meal_plan') NOT NULL DEFAULT 'manual'");
    }

    public function down(): void
    {
        DB::table('shopping_items')->where('source', 'meal_plan')->update(['source' => 'recipe']);
        DB::statement("ALTER TABLE shopping_items MODIFY source ENUM('manual', 'inventory', 'recipe') NOT NULL DEFAULT 'manual'");
        Schema::dropIfExists('meal_plan_entries');
    }
};
