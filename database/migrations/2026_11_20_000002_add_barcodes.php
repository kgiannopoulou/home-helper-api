<?php

use App\Enums\ItemCategory;
use App\Enums\StorageLocation;
use App\Kitchen\Units;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7, day 86 (docs/kitchen.md#barcodes).
 *
 * inventory_items.barcode: the pack this item was scanned as, so the next
 * scan finds it even when no product database knows the barcode, and
 * pack_amount: how much one such pack adds.
 *
 * products: Open Food Facts answers cached by barcode, found or not. Public
 * product data shared by all households, with nothing about who scanned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('barcode', 14)->nullable()->after('ingredient');
            $table->decimal('pack_amount', 10, 3)->nullable()->after('full_amount');
            $table->index(['household_id', 'barcode']);
        });
        DB::statement('ALTER TABLE inventory_items
            ADD CONSTRAINT inventory_items_barcode_digits CHECK (barcode IS NULL OR barcode REGEXP \'^[0-9]{8,14}$\'),
            ADD CONSTRAINT inventory_items_pack_amount_positive CHECK (pack_amount IS NULL OR pack_amount > 0)');

        Schema::create('products', function (Blueprint $table) {
            $table->string('gtin', 14)->primary();
            $table->boolean('found');
            $table->string('name', 100)->nullable();
            $table->string('brand', 60)->nullable();
            $table->string('quantity', 50)->nullable();
            $table->decimal('amount', 10, 3)->nullable();
            $table->enum('unit', Units::BASE)->nullable();
            $table->string('ingredient', 60)->nullable();
            $table->enum('category', ItemCategory::values())->nullable();
            $table->enum('location', StorageLocation::values())->nullable();
            $table->string('image_url', 300)->nullable();
            $table->string('source', 20);
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        DB::statement('ALTER TABLE inventory_items
            DROP CHECK inventory_items_barcode_digits,
            DROP CHECK inventory_items_pack_amount_positive');
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'barcode']);
            $table->dropColumn(['barcode', 'pack_amount']);
        });
    }
};
