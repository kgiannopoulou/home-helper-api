<?php

namespace App\SmartHome\Events;

use App\Models\SmartHomeEvent;
use Illuminate\Foundation\Events\Dispatchable;

/** A row in the house's event log: notifications (day 80) and rules (day 81) listen */
class HomeEventRecorded
{
    use Dispatchable;

    public function __construct(public readonly SmartHomeEvent $event) {}
}
