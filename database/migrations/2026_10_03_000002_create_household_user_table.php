<?php

use App\Enums\HouseholdRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_user', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', HouseholdRole::values())->default(HouseholdRole::Member->value);
            $table->timestamps(3);

            $table->unique(['household_id', 'user_id']);
            // "Which households am I in?" starts from the user
            $table->index(['user_id', 'household_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_user');
    }
};
