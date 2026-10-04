<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A phone that gets push notifications. It hangs off the Sanctum token the phone
     * logged in with, so logging out (which deletes the token) stops its pushes too.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()->constrained()->cascadeOnDelete();
            // ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]
            $table->string('expo_token')->unique();
            $table->enum('platform', ['ios', 'android'])->nullable();
            $table->string('name', 100)->nullable();
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
