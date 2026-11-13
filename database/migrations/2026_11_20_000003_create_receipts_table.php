<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7, day 87 (docs/kitchen.md#receipts): a receipt read from a photo or
 * pasted text, as a draft until someone checks it.
 *
 * A draft keeps the OCR text (card numbers masked) and the lines the parser
 * found; the photo itself is on the private disk only until the receipt is
 * imported or thrown away, and drafts are pruned after a week. Importing
 * makes the shopping trip, purchases, prices, the expense and the kitchen
 * amounts, in one transaction, and links the trip here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['draft', 'imported', 'discarded'])->default('draft');
            $table->enum('source', ['photo', 'text']);
            $table->string('store', 100)->nullable();
            $table->date('purchased_on')->nullable();
            $table->decimal('total', 10, 2)->nullable();
            $table->boolean('adds_up')->default(false);
            $table->text('ocr_text');
            $table->json('lines');
            $table->string('image_path', 200)->nullable();
            $table->foreignUlid('shopping_trip_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('imported_at', 3)->nullable();
            $table->timestamps(3);

            $table->index(['household_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
