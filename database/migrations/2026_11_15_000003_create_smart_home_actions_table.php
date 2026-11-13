<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything Home Helper asked a device to do, and who asked
 * (App\SmartHome\Control): a button, the phone, an automation rule, the
 * assistant. An audit trail for "who turned the heater on?", and the queue
 * for sensitive actions waiting for the owner's approval.
 *
 *   done | failed                     ran straight away
 *   awaiting_approval → approved → done | failed
 *                     → declined | expired
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_home_actions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->constrained('smart_devices')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('source', ['dashboard', 'api', 'rule', 'assistant']);
            $table->ulid('rule_id')->nullable();
            $table->string('action', 30);
            $table->json('params')->nullable();
            $table->enum('status', ['done', 'failed', 'awaiting_approval', 'approved', 'declined', 'expired']);
            $table->string('error', 300)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at', 3)->nullable();
            $table->timestamp('expires_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps(3);

            $table->index(['household_id', 'created_at']);
            $table->index(['household_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_home_actions');
    }
};
