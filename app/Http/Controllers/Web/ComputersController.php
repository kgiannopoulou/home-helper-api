<?php

namespace App\Http\Controllers\Web;

use App\Agents\AgentEvents;
use App\Agents\Commands;
use App\Agents\Enrollment;
use App\Agents\Heartbeats;
use App\Agents\Inventory;
use App\Agents\Releases;
use App\Models\Agent;
use App\Models\AgentCommand;
use App\Models\AgentEvent;
use App\Models\AgentMetric;
use App\Models\User;
use App\Policies\AgentPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Computers page (resources/js/pages/computers.tsx): the household's
 * computers running the local agent (agent/docs/design.md), their readings,
 * microphones and speakers, events and commands; pairing, removing and
 * updating them. It polls every 10 seconds.
 */
class ComputersController extends WebController
{
    public function show(Request $request, Releases $releases): Response
    {
        $household = $this->household($request);
        $user = $request->user();
        $agents = $household->agents()->active()->orderBy('name')->get();
        $policy = new AgentPolicy;

        return Inertia::render('computers', [
            'computers' => $agents->map(fn (Agent $a) => $this->computer($a, $user, $releases))->values()->all(),
            'events' => AgentEvent::query()->where('household_id', $household->id)->with('agent:id,name')
                ->orderByDesc('occurred_at')->limit(50)->get()
                ->map(fn (AgentEvent $e) => [
                    'id' => $e->id,
                    'computer' => $e->agent->name,
                    'type' => $e->type,
                    'severity' => $e->severity,
                    'message' => $e->message,
                    'at' => $e->occurred_at->toIso8601String(),
                ])->all(),
            'commands' => AgentCommand::query()->where('household_id', $household->id)
                ->with(['agent', 'requester:id,name', 'confirmer:id,name'])
                ->latest()->limit(30)->get()
                ->map(fn (AgentCommand $c) => [
                    'id' => $c->id,
                    'computer' => $c->agent->name,
                    'label' => $c->label(),
                    'status' => $c->status,
                    'source' => $c->source,
                    'sensitive' => $c->sensitive,
                    'requested_by' => $c->requester?->name,
                    'confirmed_by' => $c->confirmer?->name,
                    'exit_code' => $c->exit_code,
                    'output' => $c->output,
                    'truncated' => $c->truncated,
                    'reason' => $c->reason,
                    'duration_ms' => $c->duration_ms,
                    'created_at' => $c->created_at->toIso8601String(),
                    'can_confirm' => $c->status === 'awaiting_confirmation' && $policy->confirm($user, $c->agent),
                    'can_cancel' => in_array($c->status, ['awaiting_confirmation', 'queued'], true)
                        && ($c->requested_by === $user->id || $policy->manage($user, $c->agent)),
                ])->all(),
            'can' => ['pair' => $policy->pair($user, $household)],
            'pairing' => $request->session()->get('pairing'),
            'heartbeat_seconds' => (int) config('homehelper.agents.heartbeat_seconds'),
        ]);
    }

    /** @return array<string, mixed> */
    private function computer(Agent $agent, User $user, Releases $releases): array
    {
        $metrics = $agent->metrics ?? [];
        $inventory = $agent->inventory ?? [];
        $release = $releases->newestFor($agent);
        $policy = new AgentPolicy;

        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'hostname' => $agent->hostname,
            'platform' => $agent->platform,
            'version' => $agent->version,
            'online' => $agent->isOnline(),
            'last_seen_at' => $agent->last_seen_at?->toIso8601String(),
            'started_at' => $agent->started_at?->toIso8601String(),
            'os' => trim(($inventory['os']['name'] ?? '').' '.($inventory['os']['version'] ?? '')) ?: null,
            'cpu_model' => $inventory['cpu']['model'] ?? null,
            'cores' => $inventory['cpu']['cores'] ?? null,
            'cpu_percent' => $metrics['cpu_percent'] ?? null,
            'memory' => isset($metrics['memory']) ? [
                'total_bytes' => $metrics['memory']['total_bytes'],
                'used_bytes' => $metrics['memory']['used_bytes'],
                'percent' => Heartbeats::memoryPercent($metrics),
            ] : null,
            'disks' => array_map(fn (array $d) => [
                'mount' => $d['mount'],
                'total_bytes' => $d['total_bytes'],
                'free_bytes' => $d['free_bytes'],
                'used_percent' => $d['total_bytes'] > 0 ? round(100 * (1 - $d['free_bytes'] / $d['total_bytes']), 1) : null,
            ], $metrics['disks'] ?? []),
            'temperatures' => $metrics['temperatures'] ?? [],
            'microphones' => Inventory::audio($agent, 'input'),
            'speakers' => Inventory::audio($agent, 'output'),
            'commands' => Inventory::commands($agent),
            'outbox_pending' => $agent->outbox_pending,
            'update' => $release ? [
                'version' => $release->version,
                'notes' => $release->notes,
                'requested' => $agent->update_requested_version === $release->version,
            ] : null,
            'history' => $this->history($agent),
            'can' => ['run' => $policy->run($user, $agent), 'manage' => $policy->manage($user, $agent)],
        ];
    }

    /**
     * The last 24 hours in 15-minute buckets: the highest reading of each,
     * because a spike is what you want to see
     *
     * @return list<array{at: string, cpu: ?float, memory: ?float, disk: ?float, temperature: ?float}>
     */
    private function history(Agent $agent): array
    {
        $rows = AgentMetric::query()
            ->where('agent_id', $agent->id)
            ->where('recorded_at', '>=', CarbonImmutable::now()->subDay())
            ->orderBy('recorded_at')
            ->get();

        $buckets = [];
        foreach ($rows as $m) {
            $slot = intdiv($m->recorded_at->getTimestamp(), 900);
            $b = $buckets[$slot] ?? ['at' => CarbonImmutable::createFromTimestamp($slot * 900)->toIso8601String(), 'cpu' => null, 'memory' => null, 'disk' => null, 'temperature' => null];
            $b['cpu'] = self::higher($b['cpu'], $m->cpu_percent);
            $b['memory'] = self::higher($b['memory'], $m->memory_percent);
            $b['disk'] = self::higher($b['disk'], $m->disk_used_percent);
            $b['temperature'] = self::higher($b['temperature'], $m->temperature_c);
            $buckets[$slot] = $b;
        }

        return array_values($buckets);
    }

    private static function higher(?float $a, ?float $b): ?float
    {
        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }

    public function pair(Request $request, Enrollment $enrollment): RedirectResponse
    {
        $household = $this->household($request);
        Gate::authorize('pair', [Agent::class, $household]);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:80']]);

        [$code, $row] = $enrollment->createCode($household, $request->user(), $data['name'] ?? null);

        // Shown once, on the next page load
        return to_route('computers')->with('pairing', ['code' => $code, 'expires_at' => $row->expires_at->toIso8601String()]);
    }

    public function run(Request $request, Agent $agent, Commands $commands): RedirectResponse
    {
        $this->inHousehold($request, $agent);
        $data = $request->validate([
            'command' => ['required', 'string', 'max:50'],
            'params' => ['nullable', 'array', 'max:10'],
            'params.*' => ['nullable', 'string', 'max:200'],
        ]);
        $commands->request($agent, $request->user(), $data['command'], array_filter($data['params'] ?? [], fn ($v) => $v !== null));

        return to_route('computers');
    }

    public function confirm(Request $request, AgentCommand $command, Commands $commands): RedirectResponse
    {
        $this->inHousehold($request, $command->agent);
        $commands->confirm($command, $request->user());

        return to_route('computers');
    }

    public function cancel(Request $request, AgentCommand $command, Commands $commands): RedirectResponse
    {
        $this->inHousehold($request, $command->agent);
        $commands->cancel($command, $request->user());

        return to_route('computers');
    }

    public function update(Request $request, Agent $agent, Releases $releases): RedirectResponse
    {
        $this->inHousehold($request, $agent);
        $releases->requestInstall($agent, $request->user());

        return to_route('computers');
    }

    public function rename(Request $request, Agent $agent): RedirectResponse
    {
        $this->inHousehold($request, $agent);
        Gate::authorize('manage', $agent);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $agent->update(['name' => $data['name']]);

        return to_route('computers');
    }

    /** Its secret stops working at once; the agent stops at its next heartbeat */
    public function revoke(Request $request, Agent $agent, AgentEvents $events): RedirectResponse
    {
        $this->inHousehold($request, $agent);
        Gate::authorize('manage', $agent);
        $agent->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
        $agent->commands()->whereIn('status', ['awaiting_confirmation', 'queued'])->update(['status' => 'cancelled', 'finished_at' => now()]);
        $events->record($agent, 'agent.revoked', 'info', $agent->name.' was removed by '.$request->user()->name);

        return to_route('computers');
    }

    /** Another household's computer is a 404, not a 403: its id says nothing */
    private function inHousehold(Request $request, Agent $agent): void
    {
        abort_unless($agent->household_id === $this->household($request)->id && ! $agent->isRevoked(), 404);
    }
}
