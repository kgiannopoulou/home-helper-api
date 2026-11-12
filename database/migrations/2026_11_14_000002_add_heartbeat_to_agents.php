<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each heartbeat brings (agent/docs/protocol.md#post-heartbeat): the
 * latest readings on the agent row, and one row a minute in agent_metrics
 * for the dashboard's charts (kept homehelper.agents.metrics_keep_days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->timestamp('started_at', 3)->nullable()->after('last_ip');
            $table->unsignedBigInteger('uptime_seconds')->nullable()->after('started_at');
            $table->json('metrics')->nullable()->after('uptime_seconds');
            $table->unsignedInteger('outbox_pending')->default(0)->after('metrics');
            $table->unsignedInteger('outbox_dropped')->default(0)->after('outbox_pending');
            // What the computer has (POST inventory): the heartbeat carries the hash of
            // the agent's last one, so the server asks again only when it lost it
            $table->json('inventory')->nullable()->after('outbox_dropped');
            $table->char('inventory_hash', 128)->nullable()->after('inventory');
            $table->timestamp('inventory_at', 3)->nullable()->after('inventory_hash');
        });

        Schema::create('agent_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('agent_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at', 3);
            $table->decimal('cpu_percent', 5, 2)->nullable();
            $table->decimal('memory_percent', 5, 2)->nullable();
            // The fullest disk: the one that will run out first
            $table->decimal('disk_used_percent', 5, 2)->nullable();
            // The hottest sensor
            $table->decimal('temperature_c', 5, 1)->nullable();

            $table->index(['agent_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_metrics');
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'uptime_seconds', 'metrics', 'outbox_pending', 'outbox_dropped', 'inventory', 'inventory_hash', 'inventory_at']);
        });
    }
};
