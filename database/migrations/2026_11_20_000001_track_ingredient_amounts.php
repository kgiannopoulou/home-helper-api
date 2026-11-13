<?php

use App\Kitchen\Ingredients;
use App\Kitchen\Units;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7, day 85 (docs/kitchen.md): the inventory learns amounts.
 *
 * inventory_items keeps its free-text quantity (the phone still sends it) and
 * gains a measured amount in a base unit (g, ml or pieces), the size of a
 * full pack (so the level follows the amount), and an ingredient key that
 * recipes, receipts and barcodes match on (App\Kitchen\Ingredients).
 *
 * kitchen_movements records every change of an amount: bought, used, cooked,
 * wasted, expired, adjusted. Signed: what came in is positive. The waste
 * report (day 88) and "how fast do we use milk" read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('ingredient', 60)->nullable()->after('name');
            $table->decimal('amount', 10, 3)->nullable()->after('quantity');
            $table->enum('unit', Units::BASE)->nullable()->after('amount');
            $table->decimal('full_amount', 10, 3)->nullable()->after('unit');
            $table->index(['household_id', 'ingredient']);
        });
        DB::statement('ALTER TABLE inventory_items
            ADD CONSTRAINT inventory_items_amount_positive CHECK (amount IS NULL OR amount >= 0),
            ADD CONSTRAINT inventory_items_full_amount_positive CHECK (full_amount IS NULL OR full_amount > 0),
            ADD CONSTRAINT inventory_items_amount_has_unit CHECK (amount IS NULL OR unit IS NOT NULL)');

        Schema::create('kitchen_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('kind', ['bought', 'used', 'cooked', 'wasted', 'expired', 'adjusted']);
            $table->decimal('amount', 10, 3);
            $table->enum('unit', Units::BASE);
            $table->string('note', 200)->nullable();
            $table->timestamp('occurred_at', 3);
            $table->timestamps(3);

            $table->index(['household_id', 'occurred_at']);
            $table->index(['inventory_item_id', 'occurred_at']);
            $table->index(['household_id', 'kind', 'occurred_at']);
        });

        // Existing rows: the key from the name, an amount from the quantity text when it has one
        DB::table('inventory_items')->orderBy('id')->each(function (object $row) {
            $parsed = Units::parse($row->quantity);
            DB::table('inventory_items')->where('id', $row->id)->update([
                'ingredient' => Ingredients::key($row->name),
                'amount' => $parsed[0] ?? null,
                'unit' => $parsed[1] ?? null,
                'full_amount' => $parsed && $parsed[0] > 0 ? $parsed[0] : null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_movements');
        DB::statement('ALTER TABLE inventory_items
            DROP CHECK inventory_items_amount_positive,
            DROP CHECK inventory_items_full_amount_positive,
            DROP CHECK inventory_items_amount_has_unit');
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'ingredient']);
            $table->dropColumn(['ingredient', 'amount', 'unit', 'full_amount']);
        });
    }
};
