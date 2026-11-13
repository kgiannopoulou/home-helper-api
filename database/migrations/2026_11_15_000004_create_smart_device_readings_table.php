<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Temperature and humidity over time (App\SmartHome\Readings): at most one
 * row per sensor per minute, kept for homehelper.smart_home.readings_keep_days.
 * Enough for "the bathroom stayed above 70 % for two hours after showers",
 * not a weather station's archive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_device_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('device_id')->constrained('smart_devices')->cascadeOnDelete();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 8, 2);
            $table->timestamp('recorded_at', 3);

            $table->index(['device_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_device_readings');
    }
};
