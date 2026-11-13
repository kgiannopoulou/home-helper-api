<?php

namespace App\SmartHome\Automation;

use App\Models\Household;
use App\Models\SmartDevice;
use App\SmartHome\Control;
use App\SmartHome\Notifications;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The only shapes a rule can have (docs/smart-home.md#automations). Checked
 * on every save, so the engine never meets a rule it can't understand:
 *
 * trigger, exactly one of:
 *   {type: state,   device_id, to, from?}         a device changes to a state
 *   {type: numeric, device_id, above? | below?}   a sensor crosses a value
 *   {type: event,   event, device_id?}            an event in the house's log (door.left_open...)
 *   {type: time,    at: "HH:MM", days?: [1-7]}    a time of day, Monday = 1
 *
 * conditions, all must hold (up to 5):
 *   {type: time,    after: "HH:MM", before: "HH:MM"}   wraps midnight
 *   {type: sun,     is: up | down}                      from the household's location
 *   {type: days,    days: [1-7]}
 *   {type: state,   device_id, is}
 *   {type: numeric, device_id, above? | below?}
 *
 * actions, in order (1 to 5):
 *   {type: device, device_id, action, brightness?}     anything Control allows for that device
 *   {type: notify, message}                             a push to the household
 */
class RuleValidator
{
    public const TRIGGERS = ['state', 'numeric', 'event', 'time'];

    public const CONDITIONS = ['time', 'sun', 'days', 'state', 'numeric'];

    public const ACTIONS = ['device', 'notify'];

    /**
     * Events a rule can start from: the event log's types (App\SmartHome\Notifications::TYPES)
     *
     * @return list<string>
     */
    public static function eventTypes(): array
    {
        return array_values(array_diff(array_keys(Notifications::TYPES), ['approval.requested', 'automation.failed']));
    }

    /**
     * @param  array<string, mixed>  $input  name, trigger, conditions, actions, cooldown_minutes, enabled
     * @return array{name: string, trigger: array<string, mixed>, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, cooldown_minutes: int, enabled: bool}
     *
     * @throws ValidationException
     */
    public function validate(Household $household, array $input): array
    {
        $hhmm = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
            'cooldown_minutes' => ['sometimes', 'integer', 'between:0,1440'],

            'trigger' => ['required', 'array'],
            'trigger.type' => ['required', Rule::in(self::TRIGGERS)],
            'trigger.device_id' => ['required_if:trigger.type,state,numeric', 'nullable', 'string', 'size:26'],
            'trigger.to' => ['required_if:trigger.type,state', 'nullable', 'string', 'max:40'],
            'trigger.from' => ['nullable', 'string', 'max:40'],
            'trigger.above' => ['nullable', 'numeric'],
            'trigger.below' => ['nullable', 'numeric'],
            'trigger.event' => ['required_if:trigger.type,event', 'nullable', Rule::in(self::eventTypes())],
            'trigger.at' => ['required_if:trigger.type,time', 'nullable', 'string', $hhmm],
            'trigger.days' => ['nullable', 'array', 'max:7'],
            'trigger.days.*' => ['integer', 'between:1,7'],

            'conditions' => ['present', 'array', 'max:5'],
            'conditions.*.type' => ['required', Rule::in(self::CONDITIONS)],
            'conditions.*.after' => ['required_if:conditions.*.type,time', 'nullable', 'string', $hhmm],
            'conditions.*.before' => ['required_if:conditions.*.type,time', 'nullable', 'string', $hhmm],
            'conditions.*.is' => ['required_if:conditions.*.type,sun,state', 'nullable', 'string', 'max:40'],
            'conditions.*.days' => ['required_if:conditions.*.type,days', 'nullable', 'array', 'min:1', 'max:7'],
            'conditions.*.days.*' => ['integer', 'between:1,7'],
            'conditions.*.device_id' => ['required_if:conditions.*.type,state,numeric', 'nullable', 'string', 'size:26'],
            'conditions.*.above' => ['nullable', 'numeric'],
            'conditions.*.below' => ['nullable', 'numeric'],

            'actions' => ['required', 'array', 'min:1', 'max:5'],
            'actions.*.type' => ['required', Rule::in(self::ACTIONS)],
            'actions.*.device_id' => ['required_if:actions.*.type,device', 'nullable', 'string', 'size:26'],
            'actions.*.action' => ['required_if:actions.*.type,device', 'nullable', 'string', 'in:turn_on,turn_off,toggle,set_brightness,lock,unlock'],
            'actions.*.brightness' => ['nullable', 'integer', 'between:1,100'],
            'actions.*.message' => ['required_if:actions.*.type,notify', 'nullable', 'string', 'max:200'],
        ])->validate();

        $devices = SmartDevice::query()->where('household_id', $household->id)->active()->get()->keyBy('id');
        $errors = [];
        $device = function (?string $id, string $field) use ($devices, &$errors): ?SmartDevice {
            $d = $id ? $devices->get(strtolower($id)) : null;
            if (! $d) {
                $errors[$field] = 'Choose one of the household\'s devices.';
            }

            return $d;
        };

        // The trigger
        $t = $data['trigger'];
        $trigger = ['type' => $t['type']];
        switch ($t['type']) {
            case 'state':
                $d = $device($t['device_id'] ?? null, 'trigger.device_id');
                $trigger += ['device_id' => $d?->id, 'to' => $t['to']] + (isset($t['from']) ? ['from' => $t['from']] : []);
                break;
            case 'numeric':
                $d = $device($t['device_id'] ?? null, 'trigger.device_id');
                if ($d && $d->numericState() === null && ! in_array($d->kind, ['temperature', 'humidity', 'sensor'], true)) {
                    $errors['trigger.device_id'] = "{$d->name} doesn't measure anything.";
                }
                $trigger += ['device_id' => $d?->id] + self::bounds($t, 'trigger', $errors);
                break;
            case 'event':
                $trigger += ['event' => $t['event']];
                if (! empty($t['device_id'])) {
                    $trigger['device_id'] = $device($t['device_id'], 'trigger.device_id')?->id;
                }
                break;
            case 'time':
                $trigger += ['at' => $t['at']] + (! empty($t['days']) ? ['days' => self::days($t['days'])] : []);
                break;
        }

        // Conditions
        $conditions = [];
        foreach ($data['conditions'] as $i => $c) {
            if ($c['type'] === 'sun' && ! in_array($c['is'], ['up', 'down'], true)) {
                $errors["conditions.{$i}.is"] = 'The sun is "up" or "down".';
            }
            $conditions[] = match ($c['type']) {
                'time' => ['type' => 'time', 'after' => $c['after'], 'before' => $c['before']],
                'sun' => ['type' => 'sun', 'is' => $c['is']],
                'days' => ['type' => 'days', 'days' => self::days($c['days'])],
                'state' => ['type' => 'state', 'device_id' => $device($c['device_id'] ?? null, "conditions.{$i}.device_id")?->id, 'is' => $c['is']],
                default => ['type' => 'numeric', 'device_id' => $device($c['device_id'] ?? null, "conditions.{$i}.device_id")?->id] + self::bounds($c, "conditions.{$i}", $errors),
            };
        }

        // Actions: what Control would accept for that device, checked now rather than at 3 am
        $actions = [];
        foreach ($data['actions'] as $i => $a) {
            if ($a['type'] === 'notify') {
                $actions[] = ['type' => 'notify', 'message' => $a['message']];

                continue;
            }
            $d = $device($a['device_id'] ?? null, "actions.{$i}.device_id");
            $action = ['type' => 'device', 'device_id' => $d?->id, 'action' => $a['action']] + (isset($a['brightness']) ? ['brightness' => (int) $a['brightness']] : []);
            if ($d) {
                $allowed = Control::ACTIONS[$d->kind] ?? [];
                if (! isset($allowed[$a['action']]) || ($a['action'] === 'set_brightness' && ! isset($a['brightness']))) {
                    $errors["actions.{$i}.action"] = "{$d->name} can't do that.";
                }
            }
            $actions[] = $action;
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => $data['name'],
            'trigger' => $trigger,
            'conditions' => $conditions,
            'actions' => $actions,
            'cooldown_minutes' => (int) ($data['cooldown_minutes'] ?? 1),
            'enabled' => (bool) ($data['enabled'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @param  array<string, string>  $errors
     * @return array{above?: float, below?: float}
     */
    private static function bounds(array $in, string $field, array &$errors): array
    {
        $out = [];
        if (isset($in['above'])) {
            $out['above'] = (float) $in['above'];
        }
        if (isset($in['below'])) {
            $out['below'] = (float) $in['below'];
        }
        if ($out === [] || count($out) === 2) {
            $errors["{$field}.above"] = 'Give either "above" or "below".';
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $days
     * @return list<int>
     */
    private static function days(array $days): array
    {
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);

        return $days;
    }
}
