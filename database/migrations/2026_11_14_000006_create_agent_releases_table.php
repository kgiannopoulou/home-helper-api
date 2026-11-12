<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signed agent releases (agent/docs/updates.md), one per version and platform,
 * made by php artisan agent:release. The signature is Ed25519 over
 * "HH-AGENT-RELEASE-V1\n{version}\n{platform}\n{sha512}\n{size}"; the agent
 * checks it against the public key in its own config.
 *
 * agents.update_requested_version: the owner pressed Install for this version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_releases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('version', 40);
            $table->string('platform', 30);
            // A path on this server (storage, served signed to agents) or an https URL
            $table->string('path', 255)->nullable();
            $table->string('url', 500)->nullable();
            $table->char('sha512', 128);
            $table->unsignedBigInteger('size');
            $table->char('signature', 128);
            $table->text('notes')->nullable();
            $table->timestamp('published_at', 3);
            $table->timestamps(3);

            $table->unique(['version', 'platform']);
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->string('update_requested_version', 40)->nullable()->after('git_sha');
            $table->foreignId('update_requested_by')->nullable()->after('update_requested_version')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('update_requested_by');
            $table->dropColumn('update_requested_version');
        });
        Schema::dropIfExists('agent_releases');
    }
};
