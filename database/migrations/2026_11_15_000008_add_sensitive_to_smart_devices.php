<?php

use App\Models\SmartDevice;
use App\SmartHome\Sensitivity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sensitive devices (App\SmartHome\Sensitivity): unlocking the front door,
 * switching on something that heats. Their risky action needs the owner's
 * approval unless the owner does it. Set from the kind and name when a
 * device is found; the owner can mark any other device too. Locks always are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_devices', function (Blueprint $table) {
            $table->boolean('sensitive')->default(false)->after('imported');
        });

        // Devices found before today get the same defaults as new ones
        DB::table('smart_devices')->orderBy('id')->each(function (object $row) {
            $device = (new SmartDevice)->forceFill(['kind' => $row->kind, 'name' => $row->name, 'device_class' => $row->device_class]);
            if (Sensitivity::byDefault($device)) {
                DB::table('smart_devices')->where('id', $row->id)->update(['sensitive' => true]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('smart_devices', function (Blueprint $table) {
            $table->dropColumn('sensitive');
        });
    }
};
