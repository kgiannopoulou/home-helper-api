<?php

use App\Voice\ResponseStyle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: voice (docs/voice.md).
 *
 * voice_settings          one row per person: voice, style, speed, wake word,
 *                         continuous conversation, and their privacy choices
 * ai_conversations.channel whether a conversation was spoken, so voice history
 *                         can be kept shorter, left out of memory or deleted on its own
 *
 * There is no table for audio: recordings are never stored, and spoken
 * answers live in the cache for a few minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('voice', 60)->nullable();
            $table->enum('style', ResponseStyle::values())->default(ResponseStyle::Conversational->value);
            $table->decimal('rate', 3, 2)->default(1.00);
            $table->string('language', 5)->nullable(); // null: detect it
            // Listening for the wake word is off until the person turns it on
            $table->boolean('wake_word')->default(false);
            // After an answer, listen for a follow-up without the wake word
            $table->boolean('continuous')->default(true);
            // Privacy: delete voice conversations after this many days (null: keep them like typed ones)
            $table->unsignedSmallInteger('keep_days')->nullable();
            // Privacy: may voice conversations be summarised into long-term memory?
            $table->boolean('remember')->default(true);
            $table->timestamps(3);
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->enum('channel', ['text', 'voice'])->default('text')->after('title');
            $table->index(['user_id', 'channel', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'channel', 'updated_at']);
            $table->dropColumn('channel');
        });
        Schema::dropIfExists('voice_settings');
    }
};
