<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('emoji', 16)->default('🏠');
            // Personal routines (skincare, plants…) rather than cleaning
            $table->boolean('personal')->default(false);
            $table->timestamps(3);
            $table->softDeletes(precision: 3);
            // 1 while the row is live, NULL once soft-deleted. Unique keys include it, so a
            // deleted row doesn't block adding the same name again (NULLs never clash).
            $table->boolean('alive')->nullable()->storedAs('if(deleted_at is null, 1, null)');

            $table->unique(['household_id', 'name', 'alive']);
            $table->index(['household_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
