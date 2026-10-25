<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the home is, for the weather forecast that plant care reads. It belongs
     * to the household, not to each plant: one forecast covers all of its plants.
     */
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->decimal('latitude', 8, 5)->nullable()->after('currency');
            $table->decimal('longitude', 8, 5)->nullable()->after('latitude');
        });

        DB::statement('ALTER TABLE households
            ADD CONSTRAINT households_latitude_range CHECK (latitude BETWEEN -90 AND 90),
            ADD CONSTRAINT households_longitude_range CHECK (longitude BETWEEN -180 AND 180),
            ADD CONSTRAINT households_location_pair CHECK ((latitude IS NULL) = (longitude IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE households
            DROP CHECK households_latitude_range,
            DROP CHECK households_longitude_range,
            DROP CHECK households_location_pair');

        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
