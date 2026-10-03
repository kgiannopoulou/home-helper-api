<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every price seen for an item, not only the last one (the phone keeps only
     * the last), so price changes can be queried over time.
     */
    public function up(): void
    {
        Schema::create('item_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('shopping_trip_id')->nullable()->constrained()->nullOnDelete();
            // Lower-case, trimmed name: the same key the phone uses for its price map
            $table->string('name', 100);
            $table->decimal('price', 10, 2);
            $table->date('seen_on');
            $table->timestamps(3);
            $table->softDeletes(precision: 3);

            $table->index(['household_id', 'name', 'seen_on']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE item_prices ADD CONSTRAINT item_prices_price_not_negative CHECK (price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('item_prices');
    }
};
