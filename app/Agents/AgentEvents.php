<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentEvent;
use App\Push\ExpoPush;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Events from the household's computers (agent/docs/protocol.md#post-events)
 * and about them (offline, back online), in one table.
 *
 * Warnings and critical events also reach the household's phones, at most
 * once an hour for the same computer and kind of event, so a disk hovering
 * at the limit doesn't buzz all evening.
 */
class AgentEvents
{
    /** Push these to the phones */
    public const ALERT_TYPES = ['disk.low', 'temperature.high', 'memory.high', 'agent.offline', 'update.failed'];

    public function __construct(private ExpoPush $push) {}

    /**
     * @param  list<array{id: string, type: string, severity: string, message: string, data?: array<string, mixed>|null, occurred_at: string}>  $events  validated
     * @return array{accepted: int, duplicates: int}
     */
    public function ingest(Agent $agent, array $events): array
    {
        $now = CarbonImmutable::now();
        $rows = array_map(fn (array $e) => [
            'id' => strtolower((string) Str::ulid()),
            'agent_id' => $agent->id,
            'household_id' => $agent->household_id,
            'event_uuid' => strtolower($e['id']),
            'type' => $e['type'],
            'severity' => $e['severity'],
            'message' => mb_substr($e['message'], 0, 300),
            'data' => isset($e['data']) ? json_encode($e['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'source' => 'agent',
            // An agent's clock can be wrong: never in the future
            'occurred_at' => CarbonImmutable::parse($e['occurred_at'])->min($now)->format('Y-m-d H:i:s.v'),
            'received_at' => $now->format('Y-m-d H:i:s.v'),
        ], $events);

        $accepted = DB::table('agent_events')->insertOrIgnore($rows);

        foreach ($events as $e) {
            if ($e['severity'] !== 'info' && in_array($e['type'], self::ALERT_TYPES, true)) {
                $this->alert($agent, $e['type'], $e['message']);
            }
        }

        return ['accepted' => $accepted, 'duplicates' => count($rows) - $accepted];
    }

    /**
     * Something the server noticed about a computer
     *
     * @param  array<string, mixed>  $data
     */
    public function record(Agent $agent, string $type, string $severity, string $message, array $data = []): AgentEvent
    {
        $event = new AgentEvent([
            'agent_id' => $agent->id,
            'event_uuid' => (string) Str::uuid(),
            'type' => $type,
            'severity' => $severity,
            'message' => mb_substr($message, 0, 300),
            'data' => $data ?: null,
            'source' => 'server',
            'occurred_at' => CarbonImmutable::now(),
            'received_at' => CarbonImmutable::now(),
        ]);
        $event->household_id = $agent->household_id;
        $event->save();

        if ($severity !== 'info' && in_array($type, self::ALERT_TYPES, true)) {
            $this->alert($agent, $type, $message);
        }

        return $event;
    }

    private function alert(Agent $agent, string $type, string $message): void
    {
        if (! Cache::add("agent-alert:{$agent->id}:{$type}", 1, now()->addHour())) {
            return;
        }
        try {
            $this->push->toUsers($agent->household->users, '🖥️ '.$agent->name, $message, ['type' => 'agent_event', 'agent_id' => $agent->id, 'event' => $type]);
        } catch (Throwable $e) {
            // A push service outage must not lose the event
            Log::warning('Agent alert push failed', ['agent_id' => $agent->id, 'type' => $type, 'error' => $e->getMessage()]);
        }
    }
}
