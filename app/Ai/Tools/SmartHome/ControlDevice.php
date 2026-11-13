<?php

namespace App\Ai\Tools\SmartHome;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\SmartDevice;
use App\Policies\SmartHomePolicy;
use App\SmartHome\Control;
use Illuminate\Validation\ValidationException;

/**
 * Switch one device for the person (docs/smart-home.md#the-assistant). Two
 * gates, like every write the assistant makes, and one more:
 *
 *   1. the person confirms the proposal in the conversation (the registry
 *      turns this call into a pending action)
 *   2. it goes through App\SmartHome\Control: imported, answering, an action
 *      the device can do
 *   3. a sensitive action (unlocking, switching on a heater) is never done
 *      by the assistant, even for the owner: it becomes an approval request,
 *      and the owner approves it on the Smart home page or the phone
 */
class ControlDevice extends Tool
{
    public function __construct(private Control $control) {}

    public function name(): string
    {
        return 'control_device';
    }

    public function label(): string
    {
        return 'Switch a device';
    }

    public function writes(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Switch one smart-home device: turn a light or plug on or off, dim a light (brightness 1-100), lock or unlock a lock. Use the device\'s name as get_smart_home shows it. Unlocking and switching on sensitive devices (heaters, the coffee machine) are not done straight away: they become a request the household owner approves.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device' => ['type' => 'string', 'description' => 'The device\'s name, as get_smart_home shows it'],
                'action' => ['type' => 'string', 'enum' => ['turn_on', 'turn_off', 'set_brightness', 'lock', 'unlock']],
                'brightness' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'For set_brightness'],
            ],
            'required' => ['device', 'action'],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'device' => ['required', 'string', 'max:120'],
            'action' => ['required', 'in:turn_on,turn_off,set_brightness,lock,unlock'],
            'brightness' => ['required_if:action,set_brightness', 'nullable', 'integer', 'between:1,100'],
        ];
    }

    public function authorize(ToolContext $context): bool
    {
        return (new SmartHomePolicy)->control($context->user, $context->household);
    }

    public function describe(array $input, ToolContext $context): string
    {
        return match ($input['action']) {
            'turn_on' => "Turn on {$input['device']}",
            'turn_off' => "Turn off {$input['device']}",
            'set_brightness' => "Set {$input['device']} to {$input['brightness']}%",
            'lock' => "Lock {$input['device']}",
            'unlock' => "Ask the owner to unlock {$input['device']}",
            default => $input['action'].' '.$input['device'],
        };
    }

    public function run(array $input, ToolContext $context): array
    {
        $device = $context->household->smartDevices()->active()->where('name', $input['device'])->first();
        if (! $device instanceof SmartDevice) {
            return ['error' => 'There is no device called "'.$input['device'].'" in Home Helper. Check get_smart_home for the names.'];
        }

        try {
            $row = $this->control->request($device, $context->user, $input['action'], isset($input['brightness']) ? ['brightness' => (int) $input['brightness']] : [], 'assistant');
        } catch (ValidationException $e) {
            return ['error' => collect($e->errors())->flatten()->first()];
        }

        return match ($row->status) {
            'done' => ['done' => $row->label(), 'now' => $device->fresh()?->state],
            'awaiting_approval' => ['waiting_for_owner' => $row->label(), 'note' => 'This needs the household owner\'s approval. They have been notified and can approve it on the Smart home page or the phone within '.config('homehelper.smart_home.approval_minutes').' minutes.'],
            default => ['error' => $row->error ?? 'Home Assistant didn\'t do it.'],
        };
    }
}
