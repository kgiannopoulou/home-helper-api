<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Computers running the local agent (agent/docs/design.md).
 *
 * agents                  one enrolled computer, inside one household
 * agent_enrollment_codes  one-time pairing codes an owner creates on the dashboard
 *
 * The agent's secret signs every request (agent/docs/protocol.md#signatures),
 * so the server needs it back: it is stored encrypted with APP_KEY, never hashed.
 * Pairing codes are only compared, so they are stored as an HMAC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->string('hostname', 255)->nullable();
            $table->string('platform', 30);
            $table->string('version', 40);
            $table->string('git_sha', 40)->nullable();
            $table->text('secret');
            $table->timestamp('last_seen_at', 3)->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('revoked_at', 3)->nullable();
            $table->timestamps(3);

            $table->index(['household_id', 'revoked_at']);
        });

        Schema::create('agent_enrollment_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80)->nullable();
            $table->char('code_hash', 64)->unique();
            $table->timestamp('expires_at', 3);
            $table->timestamp('used_at', 3)->nullable();
            $table->foreignUlid('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps(3);

            $table->index(['household_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_enrollment_codes');
        Schema::dropIfExists('agents');
    }
};
