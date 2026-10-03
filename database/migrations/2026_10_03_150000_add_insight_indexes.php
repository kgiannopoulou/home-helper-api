<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Covering indexes for the insight queries (Phase 3), chosen from EXPLAIN ANALYZE:
     * see docs/explain/*.before.txt and *.after.txt.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            // Weekly spending and the budget forecast read a household's expenses by date and need
            // only these columns, so MySQL can answer from the index without reading the rows.
            // It starts like the (household_id, date) index it replaces, so it serves those queries too.
            $table->index(['household_id', 'date', 'category', 'amount', 'source', 'deleted_at'], 'expenses_household_date_covering');
            $table->dropIndex(['household_id', 'date']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            // Run-out reads a household's purchase days per item: all of it is in the index,
            // so MySQL no longer goes from the sync index to each row to check deleted_at
            $table->index(['household_id', 'inventory_item_id', 'bought_on', 'deleted_at'], 'purchases_household_item_day_covering');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('purchases_household_item_day_covering');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['household_id', 'date']);
            $table->dropIndex('expenses_household_date_covering');
        });
    }
};
