<?php

namespace App\Agents;

use App\Models\Agent;
use Carbon\CarbonImmutable;

/**
 * A heartbeat in, the agent's work out (agent/docs/protocol.md#post-heartbeat).
 *
 * In: the agent's version, uptime and latest readings. The row on `agents`
 * always has the latest; agent_metrics gets one summary row a minute.
 * Out: the server time, how often to call, whether the server wants the
 * inventory again, and the commands waiting for this computer.
 */
class Heartbeats
{
    public function __construct(private Commands $commands) {}

    /**
     * @param  array<string, mixed>  $data  validated by HeartbeatController
     * @return array<string, mixed> the reply body
     */
    public function record(Agent $agent, array $data, ?string $ip): array
    {
        $now = CarbonImmutable::now();
        $metrics = $data['metrics'] ?? null;

        $agent->forceFill([
            'version' => $data['version'],
            'platform' => $data['platform'],
            'git_sha' => $data['git_sha'] ?? $agent->git_sha,
            'started_at' => isset($data['started_at']) ? CarbonImmutable::parse($data['started_at']) : $agent->started_at,
            'uptime_seconds' => $data['uptime_seconds'] ?? null,
            'metrics' => $metrics,
            'outbox_pending' => (int) ($data['outbox']['pending'] ?? 0),
            'outbox_dropped' => (int) ($data['outbox']['dropped'] ?? 0),
            'last_seen_at' => $now,
            'last_ip' => $ip,
        ])->save();

        if (is_array($metrics)) {
            $this->sample($agent, $metrics, $now);
        }

        return [
            'server_time' => $now->getTimestamp(),
            'heartbeat_seconds' => (int) config('homehelper.agents.heartbeat_seconds'),
            'inventory_wanted' => ! isset($data['inventory_hash']) || $data['inventory_hash'] !== $agent->inventory_hash,
            'commands' => $this->commands->forHeartbeat($agent),
            'update' => null,
        ];
    }

    /**
     * The summary row for the charts, at most one a minute per agent.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function sample(Agent $agent, array $metrics, CarbonImmutable $now): void
    {
        $last = $agent->metricHistory()->max('recorded_at');
        if ($last && CarbonImmutable::parse($last)->greaterThan($now->subSeconds(55))) {
            return;
        }

        $agent->metricHistory()->create([
            'recorded_at' => $now,
            'cpu_percent' => self::clampPercent($metrics['cpu_percent'] ?? null),
            'memory_percent' => self::memoryPercent($metrics),
            'disk_used_percent' => self::fullestDisk($metrics),
            'temperature_c' => self::hottest($metrics),
        ]);
    }

    /** @param array<string, mixed> $metrics */
    public static function memoryPercent(array $metrics): ?float
    {
        $total = (float) ($metrics['memory']['total_bytes'] ?? 0);

        return $total > 0 ? self::clampPercent(100 * (float) ($metrics['memory']['used_bytes'] ?? 0) / $total) : null;
    }

    /** @param array<string, mixed> $metrics */
    public static function fullestDisk(array $metrics): ?float
    {
        $used = [];
        foreach ((array) ($metrics['disks'] ?? []) as $disk) {
            if (is_array($disk) && ($disk['total_bytes'] ?? 0) > 0) {
                $used[] = 100 * (1 - (float) ($disk['free_bytes'] ?? 0) / (float) $disk['total_bytes']);
            }
        }

        return $used === [] ? null : self::clampPercent(max($used));
    }

    /** @param array<string, mixed> $metrics */
    public static function hottest(array $metrics): ?float
    {
        $readings = [];
        foreach ((array) ($metrics['temperatures'] ?? []) as $sensor) {
            if (is_array($sensor) && is_numeric($sensor['celsius'] ?? null)) {
                $readings[] = (float) $sensor['celsius'];
            }
        }

        return $readings === [] ? null : round(max(-50, min(150, max($readings))), 1);
    }

    private static function clampPercent(mixed $value): ?float
    {
        return is_numeric($value) ? round(max(0, min(100, (float) $value)), 2) : null;
    }
}
