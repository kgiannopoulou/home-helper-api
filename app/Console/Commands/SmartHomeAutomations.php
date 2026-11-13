<?php

namespace App\Console\Commands;

use App\SmartHome\Automation\RuleEngine;
use Illuminate\Console\Command;

/**
 * php artisan smart-home:automations — run the automations due at this
 * minute, home time ("Good night at 00:30"). Scheduled every minute; the
 * other triggers (a device changing, an event) run as they happen.
 */
class SmartHomeAutomations extends Command
{
    protected $signature = 'smart-home:automations';

    protected $description = 'Run the automations scheduled for this minute';

    public function handle(RuleEngine $engine): int
    {
        $fired = $engine->tick();
        if ($fired > 0) {
            $this->line("{$fired} automations ran");
        }

        return self::SUCCESS;
    }
}
