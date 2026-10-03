<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invite links are signed URLs (Phase 2), so a stored token is no longer needed:
     * the signature already proves the link came from us and hasn't been changed.
     */
    public function up(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->dropUnique(['token_hash']);
            $table->dropColumn('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->char('token_hash', 64)->nullable()->unique()->after('role');
        });
    }
};
