<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A household's Home Assistant (docs/smart-home.md). One per household:
 * Home Assistant already joins every radio and brand into one place, so a
 * second hub would be a second Home Assistant, which nobody runs.
 *
 * Both directions are authenticated separately:
 *   - Home Helper -> Home Assistant: a long-lived access token, encrypted with APP_KEY
 *   - Home Assistant -> Home Helper (state changes): the connection's own
 *     webhook secret, stored as a SHA-256 hash and shown once
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_home_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('base_url', 255);
            $table->text('token');
            $table->char('webhook_secret_hash', 64);
            $table->enum('status', ['connected', 'unreachable', 'unauthorized', 'error'])->default('connected');
            $table->string('status_message', 300)->nullable();
            $table->string('location_name', 120)->nullable();
            $table->string('ha_version', 30)->nullable();
            $table->string('time_zone', 64)->nullable();
            $table->timestamp('last_ok_at', 3)->nullable();
            $table->timestamp('last_error_at', 3)->nullable();
            $table->timestamp('last_event_at', 3)->nullable();
            $table->timestamp('last_synced_at', 3)->nullable();
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_home_connections');
    }
};
