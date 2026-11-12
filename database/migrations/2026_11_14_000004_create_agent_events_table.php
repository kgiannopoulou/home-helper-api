<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened on the household's computers (agent/docs/protocol.md#post-events),
 * plus what the server noticed about them (offline, back online, enrolled).
 *
 * event_uuid is the agent's own id for the event: the unique key makes a resend
 * after a lost reply harmless. Server-side events get a fresh uuid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_uuid');
            $table->string('type', 40);
            $table->enum('severity', ['info', 'warning', 'critical']);
            $table->string('message', 300);
            $table->json('data')->nullable();
            $table->enum('source', ['agent', 'server'])->default('agent');
            $table->timestamp('occurred_at', 3);
            $table->timestamp('received_at', 3);

            $table->unique(['agent_id', 'event_uuid']);
            $table->index(['household_id', 'occurred_at']);
            $table->index(['agent_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_events');
    }
};
