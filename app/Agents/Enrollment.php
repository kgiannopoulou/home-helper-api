<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentEnrollmentCode;
use App\Models\Household;
use App\Models\User;
use App\Policies\AgentPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pairing a computer with a household (agent/docs/design.md#security-decisions).
 *
 * The owner creates a code on the dashboard; the person at the computer runs
 * `home-helper-agent enroll --code K7QM-2XPA`. The code is 8 characters from
 * an alphabet without look-alikes (40 bits), lasts 10 minutes, works once, and
 * the enroll endpoint is rate-limited, so guessing one is out of reach. Only
 * an HMAC of it is stored.
 */
class Enrollment
{
    /** No 0/O, 1/I/L, 2/Z, 5/S, 8/B: easy to read out and type */
    private const ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679';

    /** @return array{0: string, 1: AgentEnrollmentCode} the code to show once, and its row */
    public function createCode(Household $household, User $user, ?string $name = null): array
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $code = substr($code, 0, 4).'-'.substr($code, 4);

        $row = $household->agentEnrollmentCodes()->create([
            'user_id' => $user->id,
            'name' => $name,
            'code_hash' => self::hash($code),
            'expires_at' => CarbonImmutable::now()->addMinutes((int) config('homehelper.agents.code_minutes')),
        ]);

        return [$code, $row];
    }

    /**
     * Trades a code for a new agent. Null for a code that is wrong, used or
     * expired: the caller can't tell which, and neither can someone guessing.
     *
     * @param  array{name?: string|null, hostname?: string|null, platform: string, version: string, git_sha?: string|null}  $about
     * @return array{0: Agent, 1: string}|null the agent and its secret (64 hex characters), shown once
     */
    public function enroll(string $code, array $about, ?string $ip = null): ?array
    {
        return DB::transaction(function () use ($code, $about, $ip) {
            $row = AgentEnrollmentCode::query()
                ->where('code_hash', self::hash($code))
                ->lockForUpdate()
                ->first();
            if (! $row || ! $row->isUsable()) {
                return null;
            }

            // A household owner made the code; if they've since stopped being one, it's void
            if (! (new AgentPolicy)->pair($row->user, $row->household)) {
                return null;
            }

            $secret = bin2hex(random_bytes(32));
            $agent = $row->household->agents()->create([
                'enrolled_by' => $row->user_id,
                'name' => mb_substr($about['name'] ?? $row->name ?? $about['hostname'] ?? 'Computer', 0, 80),
                'hostname' => isset($about['hostname']) ? mb_substr($about['hostname'], 0, 255) : null,
                'platform' => $about['platform'],
                'version' => $about['version'],
                'git_sha' => $about['git_sha'] ?? null,
                'secret' => $secret,
                'last_seen_at' => CarbonImmutable::now(),
                'last_ip' => $ip,
            ]);
            $row->update(['used_at' => CarbonImmutable::now(), 'agent_id' => $agent->id]);

            return [$agent, $secret];
        });
    }

    /** Upper case, no spaces or dashes: "k7qm 2xpa" and "K7QM-2XPA" are the same code */
    public static function normalize(string $code): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($code)) ?? '';
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', 'agent-enrollment:'.self::normalize($code), (string) config('app.key'));
    }
}
