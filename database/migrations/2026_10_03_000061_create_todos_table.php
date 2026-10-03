<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 150);
            $table->date('due_on')->nullable();
            // Rough time it takes, used to fit it into free gaps
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->timestamp('done_at', 3)->nullable();
            $table->timestamps(3);
            $table->softDeletes(precision: 3);

            $table->index(['household_id', 'due_on']);
            $table->index(['household_id', 'done_at']);
            $table->index(['household_id', 'updated_at']);
        });

        DB::statement('ALTER TABLE todos ADD CONSTRAINT todos_minutes_range CHECK (minutes IS NULL OR minutes BETWEEN 1 AND 1440)');
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
