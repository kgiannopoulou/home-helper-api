<?php

namespace App\SmartHome\Automation;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Household;
use App\Models\SmartDevice;
use App\Push\ExpoPush;
use App\SmartHome\Control;
use App\SmartHome\Events\DeviceStateChanged;
use App\SmartHome\Events\HomeEventRecorded;
use App\SmartHome\HomeEvents;
use App\Support\HomeTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs the household's automations (docs/smart-home.md#automations).
 *
 *   trigger matched → cooldown passed → conditions hold → actions, in order
 *
 * Every match is an automation_runs row, including the ones a condition or
 * the cooldown stopped, so "why didn't the light come on?" has an answer.
 *
 * Actions go through App\SmartHome\Control like a button press (same checks,
 * same audit trail, source "rule"), so a rule can't do anything a person
 * couldn't. A sensitive action waits for the owner's approval (day 83).
 *
 * Loops: a rule's action changes a device, which can trigger another rule.
 * Inside one run that chain stops at MAX_DEPTH; across separate pushes the
 * cooldown does it (a rule fires at most once per cooldown_minutes).
 */
class RuleEngine
{
    public const MAX_DEPTH = 3;

    private static int $depth = 0;

    public function __construct(private Control $control, private HomeEvents $events, private ExpoPush $push) {}

    public function onStateChanged(DeviceStateChanged $e): void
    {
        $device = $e->device;
        $rules = $this->rules($device->household_id, ['state', 'numeric']);
        foreach ($rules as $rule) {
            $t = $rule->trigger;
            if (($t['device_id'] ?? null) !== $device->id) {
                continue;
            }
            $matched = match ($t['type']) {
                'state' => $e->to === $t['to'] && (! isset($t['from']) || $e->from === $t['from']),
                'numeric' => self::crossed($t, $e->from, $e->to),
                default => false,
            };
            if ($matched) {
                $this->fire($rule, "{$device->name}: {$e->from} → {$e->to}");
            }
        }
    }

    public function onEvent(HomeEventRecorded $recorded): void
    {
        $event = $recorded->event;
        foreach ($this->rules($event->household_id, ['event']) as $rule) {
            $t = $rule->trigger;
            if ($t['event'] === $event->type && (! isset($t['device_id']) || $t['device_id'] === $event->device_id)) {
                $this->fire($rule, $event->message);
            }
        }
    }

    /**
     * Time triggers (smart-home:automations, every minute): rules due at this
     * minute, home time, that haven't fired yet today
     *
     * @return int rules fired
     */
    public function tick(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $local = $now->setTimezone(HomeTime::zone());
        $fired = 0;

        foreach (AutomationRule::query()->where('enabled', true)->get() as $rule) {
            $t = $rule->trigger;
            if ($t['type'] !== 'time' || $t['at'] !== $local->format('H:i')) {
                continue;
            }
            if (isset($t['days']) && ! in_array($local->isoWeekday(), $t['days'], true)) {
                continue;
            }
            // Already fired this minute (a second scheduler run, a clock adjustment)
            if ($rule->last_fired_at && $rule->last_fired_at->greaterThanOrEqualTo($now->startOfMinute())) {
                continue;
            }
            $this->fire($rule, "It's {$t['at']}", $now);
            $fired++;
        }

        return $fired;
    }

    /** Run a rule now: its trigger matched (or the owner pressed "Run now", with $manual) */
    public function fire(AutomationRule $rule, string $why, ?CarbonImmutable $now = null, bool $manual = false): AutomationRun
    {
        $now ??= CarbonImmutable::now();
        $household = $rule->household;
        $devices = SmartDevice::query()->whereIn('id', $rule->deviceIds())->get()->keyBy('id')->all();

        if (self::$depth >= self::MAX_DEPTH) {
            return $this->record($rule, $why, 'skipped', 'stopped a chain of rules setting each other off', [], $now);
        }
        if (! $manual && $rule->last_fired_at && $rule->cooldown_minutes > 0 && $rule->last_fired_at->addMinutes($rule->cooldown_minutes)->greaterThan($now)) {
            return $this->record($rule, $why, 'skipped', "ran less than {$rule->cooldown_minutes} min ago", [], $now);
        }
        if (! $manual && ($failing = Conditions::failing($rule->conditions, $household, $devices, $now)) !== null) {
            return $this->record($rule, $why, 'skipped', $failing, [], $now);
        }

        $rule->forceFill(['last_fired_at' => $now])->save();
        self::$depth++;
        try {
            $results = [];
            foreach ($rule->actions as $action) {
                $results[] = $this->act($rule, $household, $action, $devices);
            }
        } finally {
            self::$depth--;
        }

        $statuses = array_column($results, 'status');
        $status = match (true) {
            ! array_diff($statuses, ['done']) => 'done',
            in_array('awaiting_approval', $statuses, true) && ! in_array('failed', $statuses, true) => 'awaiting_approval',
            ! array_intersect($statuses, ['done', 'awaiting_approval']) => 'failed',
            default => 'partial',
        };
        $run = $this->record($rule, $why, $status, null, $results, $now);

        if (in_array($status, ['failed', 'partial'], true)) {
            $this->events->record($rule->household_id, null, 'automation.failed', 'warning', "\"{$rule->name}\" didn't fully work: ".collect($results)->firstWhere('status', 'failed')['detail'], ['rule_id' => $rule->id, 'run_id' => $run->id]);
        }

        return $run;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, SmartDevice>  $devices
     * @return array{type: string, status: string, detail: string}
     */
    private function act(AutomationRule $rule, Household $household, array $action, array $devices): array
    {
        if ($action['type'] === 'notify') {
            try {
                $this->push->toUsers($household->users, '⚙️ '.$rule->name, $action['message'], ['type' => 'automation', 'rule_id' => $rule->id, 'url' => '/smart-home']);

                return ['type' => 'notify', 'status' => 'done', 'detail' => $action['message']];
            } catch (Throwable $e) {
                return ['type' => 'notify', 'status' => 'failed', 'detail' => 'The push service failed.'];
            }
        }

        $device = $devices[$action['device_id']] ?? null;
        if (! $device) {
            return ['type' => 'device', 'status' => 'failed', 'detail' => 'The device is no longer in Home Helper.'];
        }
        try {
            $row = $this->control->request($device, null, $action['action'], isset($action['brightness']) ? ['brightness' => $action['brightness']] : [], 'rule', $rule->id);
        } catch (ValidationException $e) {
            return ['type' => 'device', 'status' => 'failed', 'detail' => collect($e->errors())->flatten()->first()];
        }

        return ['type' => 'device', 'status' => $row->status === 'failed' ? 'failed' : $row->status, 'detail' => $row->status === 'failed' ? (string) $row->error : $row->label(), 'action_id' => $row->id];
    }

    /** @param  list<array<string, mixed>>  $results */
    private function record(AutomationRule $rule, string $why, string $status, ?string $reason, array $results, CarbonImmutable $now): AutomationRun
    {
        $run = new AutomationRun([
            'rule_id' => $rule->id,
            'trigger' => mb_substr($why, 0, 200),
            'status' => $status,
            'reason' => $reason ? mb_substr($reason, 0, 300) : null,
            'results' => $results ?: null,
            'ran_at' => $now,
        ]);
        $run->household_id = $rule->household_id;
        $run->save();
        Log::info('Automation', ['rule_id' => $rule->id, 'status' => $status, 'reason' => $reason]);

        return $run;
    }

    /**
     * @param  list<string>  $types
     * @return iterable<AutomationRule>
     */
    private function rules(string $householdId, array $types): iterable
    {
        return AutomationRule::query()
            ->where('household_id', $householdId)
            ->where('enabled', true)
            ->whereIn('trigger->type', $types)
            ->get();
    }

    /**
     * Crossing, not being: 64 → 66 crosses "above 65", 66 → 67 doesn't
     *
     * @param  array<string, mixed>  $t
     */
    public static function crossed(array $t, ?string $from, ?string $to): bool
    {
        if (! is_numeric($to)) {
            return false;
        }
        $was = is_numeric($from) ? (float) $from : null;
        $now = (float) $to;

        return isset($t['above'])
            ? $now > $t['above'] && ($was === null || $was <= $t['above'])
            : $now < $t['below'] && ($was === null || $was >= $t['below']);
    }
}
