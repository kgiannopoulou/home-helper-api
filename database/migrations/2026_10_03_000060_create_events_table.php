<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Events added in the app. Events from the phone's own calendars stay on the
     * phone and are not synced.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 150);
            $table->timestamp('starts_at', 3);
            $table->timestamp('ends_at', 3);
            $table->boolean('all_day')->default(false);
            $table->string('location', 150)->nullable();
            $table->timestamps(3);
            $table->softDeletes(precision: 3);

            $table->index(['household_id', 'starts_at']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE events ADD CONSTRAINT events_ends_after_start CHECK (ends_at >= starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
