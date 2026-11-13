<?php

namespace App\Providers;

use App\SmartHome\Events\DeviceStateChanged;
use App\SmartHome\Openings;
use App\SmartHome\Readings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The smart home (docs/smart-home.md). What listens to a device's state
 * changing, in the order it runs.
 */
class SmartHomeServiceProvider extends ServiceProvider
{
    public const LISTENERS = [
        // Temperature and humidity history (day 78)
        [Readings::class, 'handle'],
        // Doors, windows and motion become events (day 79)
        [Openings::class, 'handle'],
    ];

    public function boot(): void
    {
        foreach (self::LISTENERS as $listener) {
            Event::listen(DeviceStateChanged::class, $listener);
        }
    }
}
