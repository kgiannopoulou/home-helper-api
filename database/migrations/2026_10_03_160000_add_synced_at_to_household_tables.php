<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const TABLES = [
        'expenses', 'recurring_bills', 'budgets', 'inventory_items', 'purchases', 'shopping_items',
        'shopping_trips', 'item_prices', 'rooms', 'chores', 'chore_completions', 'supplies',
        'food_entries', 'water_entries', 'sleep_entries', 'workouts', 'weights', 'events', 'todos', 'admin_items',
    ];

    /**
     * Two clocks for sync (Phase 4):
     * - updated_at is when the row was changed, on the device that changed it. Last write wins on it.
     * - synced_at is when the server stored that change, set by MySQL on every insert and update.
     *   Phones ask for "everything since my last sync" on synced_at, so a phone whose clock is
     *   behind can't hide its changes from the others.
     *
     * The (household_id, updated_at) index was meant for the sync pull; it moves to synced_at.
     */
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->timestamp('synced_at', 3)->useCurrent()->useCurrentOnUpdate()->after('updated_at');
                $table->index(['household_id', 'synced_at']);
                $table->dropIndex("{$name}_household_id_updated_at_index");
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->index(['household_id', 'updated_at']);
                $table->dropIndex(['household_id', 'synced_at']);
                $table->dropColumn('synced_at');
            });
        }
    }
};
