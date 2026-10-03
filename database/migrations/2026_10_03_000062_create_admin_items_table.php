<?php

use App\Enums\AdminKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Life admin: bills, dentist, insurance renewals, car service…
     */
    public function up(): void
    {
        Schema::create('admin_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->enum('kind', AdminKind::values());
            $table->date('due_on');
            // Repeats every N months, 0 = one-off
            $table->unsignedTinyInteger('repeat_months')->default(0);
            // Start reminding this many days before
            $table->unsignedSmallInteger('remind_days')->default(7);
            $table->decimal('amount', 10, 2)->nullable();
            // Set on one-offs when done; repeating items move due_on forward instead
            $table->timestamp('done_at', 3)->nullable();
            $table->timestamps(3);
            $table->softDeletes(precision: 3);

            $table->index(['household_id', 'due_on']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE admin_items
            ADD CONSTRAINT admin_items_repeat_months_range CHECK (repeat_months <= 120),
            ADD CONSTRAINT admin_items_amount_not_negative CHECK (amount IS NULL OR amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_items');
    }
};
