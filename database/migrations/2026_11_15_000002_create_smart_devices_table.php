<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devices Home Helper found in Home Assistant (App\SmartHome\Discovery):
 * one row per Home Assistant entity in a domain it understands. Discovery
 * finds them; the owner chooses which ones to import (`imported`), and only
 * those appear on the dashboard, in rules and for the assistant.
 *
 * Home Assistant is the source of truth for the state; this is a copy,
 * refreshed by every sync and every pushed state change, so the dashboard
 * can show the house without waiting on the Pi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('connection_id')->constrained('smart_home_connections')->cascadeOnDelete();
            $table->foreignUlid('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity_id', 255);
            $table->string('domain', 30);
            $table->string('kind', 30);
            $table->string('name', 120);
            $table->string('area', 120)->nullable();
            $table->string('device_class', 40)->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('state', 255)->nullable();
            $table->json('ha_attributes')->nullable();
            $table->boolean('available')->default(true);
            $table->boolean('imported')->default(false);
            $table->timestamp('last_changed_at', 3)->nullable();
            $table->timestamp('seen_at', 3)->nullable();
            $table->timestamp('removed_at', 3)->nullable();
            $table->timestamps(3);

            $table->unique(['connection_id', 'entity_id']);
            $table->index(['household_id', 'imported', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_devices');
    }
};
