<?php

use App\Enums\JobStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per run of a scheduled household job: what ran, for which household,
     * how it went and how long it took. `summary` says what it did (items added, push sent…).
     */
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->string('job', 100);
            $table->enum('status', JobStatus::values())->default(JobStatus::Running->value);
            $table->date('for_date');
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at', 3);
            $table->timestamp('finished_at', 3)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->index(['household_id', 'job', 'started_at']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
    }
};
