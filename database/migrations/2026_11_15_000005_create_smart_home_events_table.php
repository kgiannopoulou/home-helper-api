<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened in the house, in household words (App\SmartHome\HomeEvents):
 * "Front door opened", "Bedroom window left open for 30 min", "Bathroom
 * humidity 72 %", "Living room lamp stopped answering". Not every state
 * change: the ones a person would want to know about, which is what the
 * timeline shows and what notifications (day 80) and rules (day 81) use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_home_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained('smart_devices')->cascadeOnDelete();
            $table->string('type', 40);
            $table->enum('severity', ['info', 'warning', 'critical']);
            $table->string('message', 300);
            $table->json('data')->nullable();
            $table->timestamp('occurred_at', 3);
            $table->timestamp('notified_at', 3)->nullable();

            $table->index(['household_id', 'occurred_at']);
            $table->index(['device_id', 'type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_home_events');
    }
};
