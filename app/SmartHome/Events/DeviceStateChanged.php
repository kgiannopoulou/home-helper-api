<?php

namespace App\SmartHome\Events;

use App\Models\SmartDevice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A device's state changed in Home Assistant: a door opened, a light went
 * off, the bathroom got more humid. Fired once per change however Home
 * Helper learned about it (the webhook, a sync, its own button press).
 *
 * Readings, notifications and the rules engine listen to it.
 */
class DeviceStateChanged
{
    use Dispatchable;

    /**
     * @param  string  $source  webhook|sync|control
     */
    public function __construct(
        public readonly SmartDevice $device,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly string $source,
    ) {}
}
