<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A child row and its parent must belong to the same household.
 *
 * Until now only the validation rules (inHousehold()) made sure of that: the
 * plain foreign key purchases.inventory_item_id → inventory_items.id would
 * accept an item from another household if a bug, a seeder or a manual
 * INSERT ever skipped them. These composite keys make MySQL refuse it:
 *
 *   purchases (household_id, inventory_item_id) → inventory_items (household_id, id)
 *
 * The parent needs a unique key on (household_id, id) for that. Only the
 * links that cascade get one: a composite key with ON DELETE SET NULL would
 * also try to null household_id, which is NOT NULL.
 */
return new class extends Migration
{
    /** child table, child column, parent table */
    private const LINKS = [
        ['purchases', 'inventory_item_id', 'inventory_items'],
        ['chores', 'room_id', 'rooms'],
        ['chore_completions', 'chore_id', 'chores'],
        ['plant_care_logs', 'plant_id', 'plants'],
        ['plant_alerts', 'plant_id', 'plants'],
    ];

    public function up(): void
    {
        foreach (array_unique(array_column(self::LINKS, 2)) as $parent) {
            Schema::table($parent, function (Blueprint $table) use ($parent) {
                $table->unique(['household_id', 'id'], "{$parent}_household_id_id_unique");
            });
        }

        foreach (self::LINKS as [$child, $column, $parent]) {
            Schema::table($child, function (Blueprint $table) use ($child, $column, $parent) {
                $table->foreign(['household_id', $column], "{$child}_same_household_fk")
                    ->references(['household_id', 'id'])
                    ->on($parent)
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::LINKS as [$child]) {
            Schema::table($child, fn (Blueprint $table) => $table->dropForeign("{$child}_same_household_fk"));
        }

        foreach (array_unique(array_column(self::LINKS, 2)) as $parent) {
            Schema::table($parent, fn (Blueprint $table) => $table->dropUnique("{$parent}_household_id_id_unique"));
        }
    }
};
