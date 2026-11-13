<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which smart-home events reach the household's phones
 * (App\SmartHome\Notifications): type => always | night | never. Null means
 * the defaults in Notifications::DEFAULTS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_home_connections', function (Blueprint $table) {
            $table->json('notify')->nullable()->after('time_zone');
        });
    }

    public function down(): void
    {
        Schema::table('smart_home_connections', function (Blueprint $table) {
            $table->dropColumn('notify');
        });
    }
};
