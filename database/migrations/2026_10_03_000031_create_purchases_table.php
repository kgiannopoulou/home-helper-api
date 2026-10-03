<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row each time an inventory item was bought. On the phone this is the
     * purchases array inside each item; here it is its own table.
     */
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Copied from the item so sync can read a household's rows without a join
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('shopping_trip_id')->nullable()->constrained()->nullOnDelete();
            $table->date('bought_on');
            $table->decimal('price', 10, 2)->nullable();
            $table->timestamps(3);
            $table->softDeletes(precision: 3);

            $table->index(['inventory_item_id', 'bought_on']);
            $table->index(['household_id', 'bought_on']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_price_not_negative CHECK (price IS NULL OR price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
