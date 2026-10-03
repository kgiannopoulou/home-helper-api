<?php

use App\Enums\Drink;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('water_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamp('drunk_at', 3);
            $table->unsignedSmallInteger('ml');
            $table->enum('drink', Drink::values())->default(Drink::Water->value);
            $table->timestamps(3);
            $table->softDeletes(precision: 3);

            $table->index(['household_id', 'date']);
            $table->index(['user_id', 'date']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE water_entries ADD CONSTRAINT water_entries_ml_range CHECK (ml BETWEEN 1 AND 3000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('water_entries');
    }
};
