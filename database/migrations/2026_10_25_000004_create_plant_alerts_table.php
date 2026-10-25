<?php

use App\Enums\AlertSeverity;
use App\Enums\PlantAlertType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What CheckPlantCare told the household, kept after the push is gone. An open
     * alert (resolved_at NULL) is also what stops the hourly check from sending
     * the same reminder again; it is resolved when the plant is cared for or the
     * weather passes. Written by the server only, so it isn't synced.
     */
    public function up(): void
    {
        Schema::create('plant_alerts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('plant_id')->constrained()->cascadeOnDelete();
            $table->enum('type', PlantAlertType::values());
            $table->enum('severity', AlertSeverity::values());
            $table->string('title', 150);
            $table->string('message', 500);
            // The numbers behind it: forecast minimum, amount, days overdue…
            $table->json('metadata')->nullable();
            $table->timestamp('triggered_at', 3);
            $table->timestamp('pushed_at', 3)->nullable();
            $table->timestamp('resolved_at', 3)->nullable();
            $table->timestamps(3);

            $table->index(['plant_id', 'type', 'resolved_at']);
            $table->index(['household_id', 'resolved_at', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_alerts');
    }
};
