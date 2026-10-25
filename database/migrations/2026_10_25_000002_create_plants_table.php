<?php

use App\Enums\LightRequirement;
use App\Enums\PlantLocation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plant in the home. Its own table, not a chore: it has a species, a place
     * in or out of the weather, temperature limits and several care schedules.
     *
     * The last_* columns come from plant_care_logs (the latest watering…), and the
     * next_* columns from them plus the interval. The server keeps both up to date,
     * so phones that water the same plant offline can't disagree about them.
     */
    public function up(): void
    {
        Schema::create('plants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('species', 100)->nullable();
            $table->string('nickname', 100)->nullable();
            $table->enum('location_type', PlantLocation::values());
            $table->enum('light_requirement', LightRequirement::values())->nullable();

            $table->unsignedSmallInteger('watering_interval_days');
            $table->unsignedInteger('water_amount_ml')->nullable();
            $table->unsignedSmallInteger('fertilize_every_days')->nullable();
            $table->unsignedSmallInteger('repot_every_months')->nullable();

            $table->decimal('minimum_temperature_c', 4, 1)->nullable();
            $table->decimal('maximum_temperature_c', 4, 1)->nullable();
            $table->boolean('frost_sensitive')->default(false);
            $table->boolean('rain_sensitive')->default(false);
            $table->boolean('wind_sensitive')->default(false);

            $table->timestamp('last_watered_at', 3)->nullable();
            $table->timestamp('next_watering_at', 3)->nullable();
            $table->timestamp('last_fertilized_at', 3)->nullable();
            $table->timestamp('next_fertilizing_at', 3)->nullable();
            $table->timestamp('last_repotted_at', 3)->nullable();
            $table->timestamp('next_repotting_at', 3)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps(3);
            $table->timestamp('synced_at', 3)->useCurrent()->useCurrentOnUpdate();
            $table->softDeletes(precision: 3);

            $table->index(['household_id', 'next_watering_at']);
            $table->index(['household_id', 'synced_at']);
        });

        DB::statement('ALTER TABLE plants
            ADD CONSTRAINT plants_watering_interval_range CHECK (watering_interval_days BETWEEN 1 AND 365),
            ADD CONSTRAINT plants_water_amount_range CHECK (water_amount_ml BETWEEN 1 AND 100000),
            ADD CONSTRAINT plants_fertilize_every_range CHECK (fertilize_every_days BETWEEN 1 AND 365),
            ADD CONSTRAINT plants_repot_every_range CHECK (repot_every_months BETWEEN 1 AND 120),
            ADD CONSTRAINT plants_temperature_order CHECK (minimum_temperature_c <= maximum_temperature_c)');
    }

    public function down(): void
    {
        Schema::dropIfExists('plants');
    }
};
