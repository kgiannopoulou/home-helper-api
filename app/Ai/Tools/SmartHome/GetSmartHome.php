<?php

namespace App\Ai\Tools\SmartHome;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\SmartDevice;
use App\Models\SmartHomeEvent;
use App\SmartHome\Capabilities;
use App\SmartHome\Comfort;
use App\SmartHome\HomeSummary;
use App\SmartHome\Sensitivity;

/**
 * The house through Home Assistant (docs/smart-home.md): every imported
 * device and its state, how each room feels, and the latest events. Read
 * from Home Helper's copy of the states, so it answers even when Home
 * Assistant is offline (and says so).
 */
class GetSmartHome extends Tool
{
    public function name(): string
    {
        return 'get_smart_home';
    }

    public function label(): string
    {
        return 'Looked at the smart home';
    }

    public function description(): string
    {
        return 'The household\'s smart home: whether Home Assistant is answering, each light, switch, lock, door, window, motion, temperature and humidity sensor with its room and state, which actions need the owner\'s approval, how each room feels (cold, humid, mould risk, the dew point) and the latest events (doors opened, windows left open, devices that stopped answering). Use it for "is the front door locked?", "which lights are on?", "is the bathroom humid?", "did anyone open the balcony door?" or before switching something.';
    }

    public function run(array $input, ToolContext $context): array
    {
        $household = $context->household;
        $summary = HomeSummary::for($household);
        if ($summary === null) {
            return ['connected' => false, 'note' => 'This household hasn\'t connected Home Assistant yet (Smart home page).'];
        }
        $devices = $household->smartDevices()->active()->with('room')->orderBy('name')->get();

        return [
            'home_assistant' => $summary['connected'] ? 'answering' : 'not answering: states may be out of date',
            'devices' => $devices->map(fn (SmartDevice $d) => array_filter([
                'name' => $d->name,
                'kind' => $d->kind,
                'room' => $d->room !== null ? $d->room->name : $d->area,
                'state' => Capabilities::stateLabel($d),
                'since' => $d->last_changed_at?->toIso8601ZuluString(),
                'can' => array_keys(array_filter([
                    'turn on/off' => Capabilities::of($d)['switchable'],
                    'dim' => Capabilities::of($d)['dimmable'],
                    'lock/unlock' => Capabilities::of($d)['lockable'],
                ])) ?: null,
                'needs_owner_approval_to' => match (true) {
                    $d->kind === 'lock' => 'unlock',
                    $d->sensitive => 'turn on',
                    default => null,
                },
            ], fn ($v) => $v !== null))->values()->all(),
            'rooms' => Comfort::rooms($devices),
            'recent_events' => SmartHomeEvent::query()->where('household_id', $household->id)->orderByDesc('occurred_at')->limit(15)->get()
                ->map(fn (SmartHomeEvent $e) => ['at' => $e->occurred_at->toIso8601ZuluString(), 'what' => $e->message])->all(),
        ];
    }
}
