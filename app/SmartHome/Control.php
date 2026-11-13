<?php

namespace App\SmartHome;

use App\Models\SmartDevice;
use App\Models\SmartHomeAction;
use App\Models\User;
use App\Policies\SmartHomePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Switching devices (docs/smart-home.md#controls). Every button press, phone
 * tap, rule and assistant request comes through here, so the checks and the
 * audit trail are the same for all of them:
 *
 *   1. the person may control this household's devices (or it's a rule)
 *   2. the device is imported, still in Home Assistant and answering
 *   3. the action is one this device can do (no "dim" for a plug)
 *   4. one smart_home_actions row records it, whatever happens next
 *   5. Home Assistant's reply updates the stored state at once, so the
 *      dashboard doesn't wait for the webhook
 */
class Control
{
    /** Action => the Home Assistant service it calls, per kind of device */
    public const ACTIONS = [
        'light' => ['turn_on' => 'turn_on', 'turn_off' => 'turn_off', 'toggle' => 'toggle', 'set_brightness' => 'turn_on'],
        'switch' => ['turn_on' => 'turn_on', 'turn_off' => 'turn_off', 'toggle' => 'toggle'],
    ];

    public function __construct(private Connections $connections, private StateChanges $changes) {}

    /**
     * @param  array<string, mixed>  $params  set_brightness: ['brightness' => 0-100]
     * @param  string  $source  dashboard|api|rule|assistant
     *
     * @throws ValidationException when it can't even be asked
     */
    public function request(SmartDevice $device, ?User $user, string $action, array $params = [], string $source = 'dashboard', ?string $ruleId = null): SmartHomeAction
    {
        $household = $device->connection->household;
        if ($user && ! (new SmartHomePolicy)->control($user, $household)) {
            throw ValidationException::withMessages(['device' => 'You can\'t control this household\'s devices.']);
        }
        $this->check($device, $action, $params);

        $row = new SmartHomeAction([
            'device_id' => $device->id,
            'requested_by' => $user?->id,
            'source' => $source,
            'rule_id' => $ruleId,
            'action' => $action,
            'params' => $params ?: null,
            'status' => 'approved',
        ]);
        $row->household_id = $device->household_id;
        $row->save();
        $row->setRelation('device', $device);

        return $this->execute($row);
    }

    /** Call Home Assistant for an approved action and record what happened */
    public function execute(SmartHomeAction $row): SmartHomeAction
    {
        $device = $row->device;
        $started = hrtime(true);
        $service = self::ACTIONS[$device->kind][$row->action] ?? $row->action;
        $data = ['entity_id' => $device->entity_id];
        if ($row->action === 'set_brightness') {
            $data['brightness_pct'] = (int) $row->params['brightness'];
        }

        try {
            $changed = $this->connections->client($device->connection)->callService($device->domain, $service, $data);
            $this->connections->succeeded($device->connection);
            $this->applyReply($device, $changed);
            $row->forceFill(['status' => 'done', 'error' => null]);
        } catch (HomeAssistantError $e) {
            $this->connections->failed($device->connection, $e);
            $row->forceFill(['status' => 'failed', 'error' => mb_substr($e->advice(), 0, 300)]);
            Log::warning('Smart home action failed', ['action_id' => $row->id, 'entity_id' => $device->entity_id, 'kind' => $e->kind]);
        }

        $row->forceFill([
            'finished_at' => CarbonImmutable::now(),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ])->save();

        return $row;
    }

    /**
     * @param  array<string, mixed>  $params
     *
     * @throws ValidationException
     */
    public function check(SmartDevice $device, string $action, array $params): void
    {
        if (! $device->imported || $device->removed_at) {
            throw ValidationException::withMessages(['device' => "{$device->name} is not one of the devices Home Helper uses."]);
        }
        if (! isset(self::ACTIONS[$device->kind][$action]) || ($action === 'set_brightness' && ! Capabilities::of($device)['dimmable'])) {
            throw ValidationException::withMessages(['action' => "{$device->name} can't do that."]);
        }
        if ($action === 'set_brightness') {
            $b = $params['brightness'] ?? null;
            if (! is_numeric($b) || (int) $b < 1 || (int) $b > 100) {
                throw ValidationException::withMessages(['brightness' => 'Brightness is 1 to 100%.']);
            }
        }
        if (! $device->available) {
            throw ValidationException::withMessages(['device' => "{$device->name} isn't answering in Home Assistant. Is it plugged in?"]);
        }
    }

    /**
     * Home Assistant returns the states that changed during the call. Some
     * integrations answer before the device does and return nothing: then
     * ask for the state once; the webhook brings the rest.
     *
     * @param  list<array<string, mixed>>  $changed
     */
    private function applyReply(SmartDevice $device, array $changed): void
    {
        $mine = collect($changed)->firstWhere('entity_id', $device->entity_id);
        if (! $mine) {
            try {
                $mine = $this->connections->client($device->connection)->state($device->entity_id);
            } catch (HomeAssistantError) {
                return;
            }
        }
        $this->changes->apply($device, isset($mine['state']) ? (string) $mine['state'] : null, (array) ($mine['attributes'] ?? []), $mine['last_changed'] ?? null, 'control');
    }
}
