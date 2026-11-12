<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the server last said a computer went offline (php artisan agents:check-offline),
 * so it says so once, and says "back online" when the next heartbeat arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->timestamp('offline_reported_at', 3)->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('offline_reported_at');
        });
    }
};
