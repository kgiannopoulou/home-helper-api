<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Household automations (docs/smart-home.md#automations): "when this
 * happens, if these hold, do that". The trigger, conditions and actions are
 * JSON with a fixed shape, checked by App\SmartHome\Automation\RuleValidator
 * on every save; there is no expression language, so a rule can only do what
 * the shape allows.
 *
 * automation_runs: every time a rule's trigger matched, whether it ran or a
 * condition stopped it, and what each action did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->boolean('enabled')->default(true);
            $table->string('template', 60)->nullable();
            $table->json('trigger');
            $table->json('conditions');
            $table->json('actions');
            $table->unsignedSmallInteger('cooldown_minutes')->default(1);
            $table->timestamp('last_fired_at', 3)->nullable();
            $table->timestamps(3);

            $table->index(['household_id', 'enabled']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->foreignUlid('household_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 200);
            $table->enum('status', ['done', 'partial', 'failed', 'skipped', 'awaiting_approval']);
            $table->string('reason', 300)->nullable();
            $table->json('results')->nullable();
            $table->timestamp('ran_at', 3);

            $table->index(['household_id', 'ran_at']);
            $table->index(['rule_id', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');
    }
};
