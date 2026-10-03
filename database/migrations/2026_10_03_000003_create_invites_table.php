<?php

use App\Enums\HouseholdRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->enum('role', HouseholdRole::values())->default(HouseholdRole::Member->value);
            // Only a SHA-256 hash is stored; the plain token goes out in the email
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at', 3);
            $table->timestamp('accepted_at', 3)->nullable();
            $table->timestamps(3);

            $table->unique(['household_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invites');
    }
};
