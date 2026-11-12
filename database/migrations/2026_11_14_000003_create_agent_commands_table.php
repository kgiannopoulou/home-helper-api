<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commands asked of a computer (agent/docs/commands.md): one row per request,
 * from asking to the result. The key must be on the computer's own allowlist;
 * the agent checks again and has the last word.
 *
 *   awaiting_confirmation → queued → sent → succeeded | failed | timed_out | rejected
 *   (or expired / cancelled before it was sent)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_commands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('source', ['dashboard', 'assistant']);
            $table->string('command_key', 50);
            $table->json('params');
            $table->boolean('sensitive');
            $table->enum('status', ['awaiting_confirmation', 'queued', 'sent', 'succeeded', 'failed', 'timed_out', 'rejected', 'expired', 'cancelled']);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at', 3)->nullable();
            $table->timestamp('expires_at', 3);
            $table->timestamp('sent_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();
            $table->integer('exit_code')->nullable();
            $table->mediumText('output')->nullable();
            $table->boolean('truncated')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps(3);

            $table->index(['agent_id', 'status']);
            $table->index(['household_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commands');
    }
};
