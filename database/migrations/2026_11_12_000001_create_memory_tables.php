<?php

use App\Enums\FactKind;
use App\Enums\MemorySource;
use App\Enums\NoteKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: memory and household intelligence (docs/memory.md).
 *
 * Short-term memory   ai_conversations.summary: the older part of a long conversation, summarised
 * Long-term memory    household_facts   things the household asked Home Helper to remember
 *                     notes             notes and uploaded documents
 *                     household_summaries  weekly and monthly digests, written by the scheduler
 *                     memory_chunks     all of the above cut into passages, with an embedding
 *                                       and a FULLTEXT index: what the assistant searches
 * Every day           briefings         each member's morning briefing, as it was sent
 *                     recommendation_feedback  "useful" / "not now" on a recommendation
 *
 * A row with user_id set in memory_chunks is private: only that person's searches see it
 * (a private note or fact, or a summary of their own conversation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            // Everything up to and including summary_through_id, summarised; only later messages are sent verbatim
            $table->text('summary')->nullable()->after('title');
            $table->unsignedBigInteger('summary_through_id')->nullable()->after('summary');
            $table->timestamp('summarized_at', 3)->nullable()->after('summary_through_id');
        });

        Schema::create('household_facts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who added it
            $table->foreignId('about_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('kind', FactKind::values());
            $table->string('content', 500);
            // Added in the app, or proposed by the assistant and confirmed by the person
            $table->enum('source', ['person', 'assistant'])->default('person');
            // Only the person who added it sees it, and only their assistant conversations
            $table->boolean('private')->default(false);
            $table->timestamps(3);

            $table->index(['household_id', 'kind']);
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('kind', NoteKind::values())->default(NoteKind::Note->value);
            $table->string('title', 150);
            // A document's extracted text; its file is on the disk at file_path
            $table->mediumText('body');
            $table->string('file_path')->nullable();
            $table->string('file_name', 150)->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->boolean('private')->default(false);
            $table->timestamps(3);

            $table->index(['household_id', 'updated_at']);
        });

        Schema::create('household_summaries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->enum('period', ['week', 'month']);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->json('stats');
            $table->text('text');
            $table->timestamps(3);

            $table->unique(['household_id', 'period', 'starts_on']);
        });

        Schema::create('memory_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // set = private to them
            $table->enum('source_type', MemorySource::values());
            $table->string('source_id', 26);
            $table->unsignedSmallInteger('chunk');
            $table->string('title', 200);
            $table->text('text');
            // The day it is about, for "last spring" questions and recency
            $table->date('occurred_on')->nullable();
            // float32 little-endian, L2-normalised; `embedder` names the model that made it
            $table->binary('embedding');
            $table->string('embedder', 60);
            $table->timestamps(3);

            $table->unique(['source_type', 'source_id', 'chunk']);
            $table->index(['household_id', 'source_type']);
        });
        // The keyword half of hybrid search (MATCH … AGAINST); InnoDB, natural language mode
        DB::statement('ALTER TABLE memory_chunks ADD FULLTEXT INDEX memory_chunks_fulltext (title, text)');

        Schema::create('briefings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->json('content');
            $table->text('text');
            $table->boolean('pushed')->default(false);
            $table->timestamps(3);

            $table->unique(['household_id', 'user_id', 'date']);
        });

        Schema::create('recommendation_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // "budget_pace:2026-11": one recommendation; `rule` is the kind it came from
            $table->string('key', 150);
            $table->string('rule', 40);
            $table->enum('verdict', ['useful', 'dismissed']);
            $table->date('hidden_until')->nullable();
            $table->timestamps(3);

            $table->unique(['household_id', 'user_id', 'key']);
            $table->index(['user_id', 'rule', 'verdict']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_feedback');
        Schema::dropIfExists('briefings');
        Schema::dropIfExists('memory_chunks');
        Schema::dropIfExists('household_summaries');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('household_facts');
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn(['summary', 'summary_through_id', 'summarized_at']);
        });
    }
};
