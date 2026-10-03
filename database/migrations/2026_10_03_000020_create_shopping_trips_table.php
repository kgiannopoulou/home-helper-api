<?php

use App\Enums\TripSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_trips', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('store', 100)->nullable();
            $table->decimal('total', 10, 2);
            $table->unsignedSmallInteger('item_count')->default(0);
            $table->enum('source', TripSource::values())->default(TripSource::Manual->value);
            $table->timestamps(3);
            $table->softDeletes(precision: 3);

            $table->index(['household_id', 'date']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE shopping_trips ADD CONSTRAINT shopping_trips_total_not_negative CHECK (total >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_trips');
    }
};
