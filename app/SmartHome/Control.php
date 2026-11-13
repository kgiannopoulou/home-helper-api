<?php

namespace App\SmartHome;

use App\Models\SmartDevice;
use App\Models\SmartHomeAction;
use App\Models\User;
use App\Policies\SmartHomePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
 *   5. a sensitive action (Sensitivity) waits for the owner's approval,
 *      unless the owner asked for it
 *   6. Home Assistant's reply updates the stored state at once, so the
 *      dashboard doesn't wait for the webhook
 */
class Control
{
    /** Action => the Home Assistant service it calls, per kind of device */
    public const ACTIONS = [
        'light' => ['turn_on' => 'turn_on', 'turn_off' => 'turn_off', 'toggle' => 'toggle', 'set_brightness' => 'turn_on'],
        'switch' => ['turn_on' => 'turn_on', 'turn_off' => 'turn_off', 'toggle' => 'toggle'],
        'lock' => ['lock' => 'lock', 'unlock' => 'unlock'],
    ];

    public function __construct(private Connections $connections, private StateChanges $changes, private HomeEvents $events) {}

    /**
     * @param  array<string, mixed>  $params  set_brightness: ['brightness' => 0-100]
     * @param  string  $source  dashboard|api|rule|assistant
     *
     * @throws ValidationException when it can't even be asked
     */
    public function request(SmartDevice $device, ?User $user, string $action, array $params = [], string $source = 'dashboard', ?string $ruleId = null): SmartHomeAction
    {
        $household = $device->connection->household;
        $policy = new SmartHomePolicy;
        if ($user && ! $policy->control($user, $household)) {
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
        $row->setRelation('device', $device);

        // The owner pressing the button is the approval; anyone or anything else waits for them
        $sensitive = Sensitivity::needsApproval($device, $action);
        $ownerAsked = $user && $policy->approve($user, $household) && in_array($source, ['dashboard', 'api'], true);
        if ($sensitive && ! $ownerAsked) {
            $row->forceFill([
                'status' => 'awaiting_approval',
                'expires_at' => CarbonImmutable::now()->addMinutes((int) config('homehelper.smart_home.approval_minutes')),
            ])->save();
            $who = $user ? $user->name : ($source === 'rule' ? 'An automation' : 'Home Helper');
            $this->events->record($device->household_id, $device, 'approval.requested', 'warning', "{$who} wants to: ".$row->label().'. Approve or decline in Home Helper.', ['action_id' => $row->id]);
            Log::info('Smart home action awaiting approval', ['action_id' => $row->id, 'entity_id' => $device->entity_id, 'source' => $source]);

            return $row;
        }

        if ($sensitive) {
            // The owner asked: they are the approval
            $row->forceFill(['decided_by' => $user->id, 'decided_at' => CarbonImmutable::now()]);
        }
        $row->save();

        return $this->execute($row);
    }

    /** The owner said yes: run it now, if it's still waiting and the device can still do it */
    public function approve(SmartHomeAction $row, User $user): SmartHomeAction
    {
        $this->mayDecide($row, $user);
        $row = DB::transaction(function () use ($row, $user) {
            $row = SmartHomeAction::query()->lockForUpdate()->findOrFail($row->id);
            $this->stillWaiting($row);
            $row->forceFill(['status' => 'approved', 'decided_by' => $user->id, 'decided_at' => CarbonImmutable::now()])->save();

            return $row;
        });
        try {
            // The world may have changed in the ten minutes it waited
            $this->check($row->device, $row->action, (array) $row->params);
        } catch (ValidationException $e) {
            $row->forceFill(['status' => 'failed', 'error' => mb_substr((string) collect($e->errors())->flatten()->first(), 0, 300), 'finished_at' => CarbonImmutable::now()])->save();

            return $row;
        }
        Log::info('Smart home action approved', ['action_id' => $row->id, 'user_id' => $user->id]);

        return $this->execute($row);
    }

    public function decline(SmartHomeAction $row, User $user): SmartHomeAction
    {
        $this->mayDecide($row, $user);

        return DB::transaction(function () use ($row, $user) {
            $row = SmartHomeAction::query()->lockForUpdate()->findOrFail($row->id);
            $this->stillWaiting($row);
            $row->forceFill(['status' => 'declined', 'decided_by' => $user->id, 'decided_at' => CarbonImmutable::now(), 'finished_at' => CarbonImmutable::now()])->save();
            Log::info('Smart home action declined', ['action_id' => $row->id, 'user_id' => $user->id]);

            return $row;
        });
    }

    /**
     * Requests nobody answered in time (scheduled every minute)
     *
     * @return int how many expired
     */
    public function expire(): int
    {
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s.v');

        return SmartHomeAction::query()->where('status', 'awaiting_approval')->where('expires_at', '<', $now)
            ->update(['status' => 'expired', 'finished_at' => $now, 'updated_at' => $now]);
    }

    private function mayDecide(SmartHomeAction $row, User $user): void
    {
        if (! (new SmartHomePolicy)->approve($user, $row->device->connection->household)) {
            throw ValidationException::withMessages(['action' => 'Only the household owner can approve this.']);
        }
    }

    private function stillWaiting(SmartHomeAction $row): void
    {
        if ($row->status !== 'awaiting_approval' || ($row->expires_at && $row->expires_at->isPast())) {
            throw ValidationException::withMessages(['action' => 'This is no longer waiting for approval.']);
        }
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
