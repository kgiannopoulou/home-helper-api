<?php

use App\Kitchen\Recipes\RecipeLibrary;
use App\Kitchen\Units;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7, day 89 (docs/kitchen.md#recipes).
 *
 * recipes: Home Helper's own (household_id NULL, slug from
 * database/data/recipes.php) and each household's. recipe_ingredients are
 * App\Kitchen\Ingredients keys with amounts for the recipe's servings, so
 * App\Queries\RecipeMatchQuery can join them to the kitchen in SQL. staple:
 * salt, oil and the like, assumed to be there.
 *
 * shopping_items.source gains `recipe`: "add what's missing to the list".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('slug', 60)->nullable()->unique();
            $table->string('name', 120);
            $table->unsignedTinyInteger('servings');
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->json('tags');
            $table->text('steps');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(3);

            $table->index(['household_id', 'name']);
        });
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_servings_range CHECK (servings BETWEEN 1 AND 50)');

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('ingredient', 60);
            $table->decimal('amount', 10, 3)->nullable();
            $table->enum('unit', Units::BASE)->nullable();
            $table->boolean('optional')->default(false);
            $table->boolean('staple')->default(false);

            $table->unique(['recipe_id', 'ingredient']);
            $table->index('ingredient');
        });

        DB::statement("ALTER TABLE shopping_items MODIFY source ENUM('manual', 'inventory', 'recipe') NOT NULL DEFAULT 'manual'");

        RecipeLibrary::sync();
    }

    public function down(): void
    {
        DB::table('shopping_items')->where('source', 'recipe')->update(['source' => 'manual']);
        DB::statement("ALTER TABLE shopping_items MODIFY source ENUM('manual', 'inventory') NOT NULL DEFAULT 'manual'");
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};
