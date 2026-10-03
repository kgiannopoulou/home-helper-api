<?php

use App\Enums\ItemCategory;
use App\Enums\ShoppingSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->enum('category', ItemCategory::values());
            $table->string('quantity', 50)->nullable();
            // A price the user typed in; otherwise the app estimates from item_prices
            $table->decimal('price', 10, 2)->nullable();
            $table->boolean('checked')->default(false);
            $table->enum('source', ShoppingSource::values())->default(ShoppingSource::Manual->value);
            $table->timestamps(3);
            $table->softDeletes(precision: 3);

            $table->index(['household_id', 'checked']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE shopping_items ADD CONSTRAINT shopping_items_price_not_negative CHECK (price IS NULL OR price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_items');
    }
};
