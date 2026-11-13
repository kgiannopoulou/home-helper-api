<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7, day 88 (docs/kitchen.md#expiry). expires_on has been there since
 * Phase 1; now it can be estimated (App\Kitchen\ShelfLife) and says so,
 * opening a pack brings it closer, and the morning push remembers which date
 * it already mentioned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->boolean('expiry_estimated')->default(false)->after('expires_on');
            $table->date('opened_on')->nullable()->after('expiry_estimated');
            $table->date('expiry_notified_on')->nullable()->after('opened_on');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['expiry_estimated', 'opened_on', 'expiry_notified_on']);
        });
    }
};
