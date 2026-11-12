<?php

namespace App\Agents;

use App\Models\Agent;
use Carbon\CarbonImmutable;

/**
 * What a computer has (agent/docs/protocol.md#post-inventory): system,
 * disks, microphones and speakers, and its command allowlist.
 *
 * The allowlist is stored only to offer its commands on the dashboard and
 * to the assistant. The server can't change it: the agent runs nothing that
 * isn't in its own config, whatever is stored here.
 */
class Inventory
{
    /**
     * @param  array<string, mixed>  $data  validated
     * @param  string  $raw  the request body: its SHA-512 is what the agent sends back in heartbeats
     */
    public function store(Agent $agent, array $data, string $raw): void
    {
        $agent->forceFill([
            'hostname' => $data['hostname'],
            'inventory' => $data,
            'inventory_hash' => hash('sha512', $raw),
            'inventory_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * The commands a person (or the assistant) may ask this computer for.
     *
     * @return list<array{key: string, description: string, sensitive: bool, params: list<array{name: string, description: ?string, pattern: string}>}>
     */
    public static function commands(Agent $agent): array
    {
        return array_values($agent->inventory['commands'] ?? []);
    }

    /**
     * @return array{key: string, description: string, sensitive: bool, params: list<array{name: string, description: ?string, pattern: string}>}|null
     */
    public static function command(Agent $agent, string $key): ?array
    {
        foreach (self::commands($agent) as $command) {
            if ($command['key'] === $key) {
                return $command;
            }
        }

        return null;
    }

    /**
     * Microphones (input) or speakers (output)
     *
     * @return list<array{id: string, name: string, direction: string, default: bool, state: string}>
     */
    public static function audio(Agent $agent, ?string $direction = null): array
    {
        return array_values(array_filter(
            $agent->inventory['audio'] ?? [],
            fn (array $d) => $direction === null || $d['direction'] === $direction,
        ));
    }
}
