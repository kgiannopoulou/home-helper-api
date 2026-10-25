<?php

use App\Enums\PlantCareType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything done to a plant, and health notes. Phones sync these events, not
     * a "last watered" timestamp: two phones watering offline both arrive, and the
     * plant's last_watered_at is the latest of them.
     */
    public function up(): void
    {
        Schema::create('plant_care_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('plant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', PlantCareType::values());
            $table->unsignedInteger('amount_ml')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('performed_at', 3);
            $table->timestamps(3);
            $table->timestamp('synced_at', 3)->useCurrent()->useCurrentOnUpdate();
            $table->softDeletes(precision: 3);

            // The latest watering of a plant
            $table->index(['plant_id', 'type', 'performed_at']);
            $table->index(['household_id', 'performed_at']);
            $table->index(['household_id', 'synced_at']);
        });

        DB::statement('ALTER TABLE plant_care_logs ADD CONSTRAINT plant_care_logs_amount_range CHECK (amount_ml BETWEEN 1 AND 100000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_care_logs');
    }
};
