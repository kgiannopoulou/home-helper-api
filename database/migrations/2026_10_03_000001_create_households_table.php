<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->char('currency', 3)->default('EUR');
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('households');
    }
};
