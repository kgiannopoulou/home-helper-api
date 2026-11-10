<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The assistant (docs/assistant.md).
 *
 * ai_conversations   one chat, private to the person who started it, inside one household
 * ai_messages        its transcript, in order: user, assistant, tool_results, event
 * ai_pending_actions a change the assistant proposed, waiting for the person to confirm it
 *
 * Messages use an auto-increment id so their order is exact, even when several
 * are written in the same millisecond.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120)->nullable();
            $table->timestamps(3);

            $table->index(['household_id', 'user_id', 'updated_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant', 'tool_results', 'event']);
            // Provider-neutral blocks for the screen, plus the provider's own blocks
            // for the assistant, replayed byte for byte on the next request
            $table->json('content');
            $table->string('model', 60)->nullable();
            $table->string('stop_reason', 30)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cache_read_tokens')->nullable();
            $table->unsignedInteger('cache_write_tokens')->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->index(['conversation_id', 'id']);
        });

        Schema::create('ai_pending_actions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tool', 60);
            $table->json('input');
            // What will happen, in words, shown on the confirm button
            $table->string('summary', 300);
            $table->enum('status', ['pending', 'confirmed', 'declined', 'expired', 'failed'])->default('pending');
            $table->json('result')->nullable();
            $table->timestamp('expires_at', 3);
            $table->timestamp('decided_at', 3)->nullable();
            $table->timestamps(3);

            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pending_actions');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
