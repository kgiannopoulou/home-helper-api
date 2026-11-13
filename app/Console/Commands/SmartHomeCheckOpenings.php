<?php

namespace App\Console\Commands;

use App\SmartHome\Openings;
use Illuminate\Console\Command;

/**
 * php artisan smart-home:check-openings — a warning for each door or window
 * open longer than homehelper.smart_home.left_open_minutes, once per opening.
 * Scheduled every minute.
 */
class SmartHomeCheckOpenings extends Command
{
    protected $signature = 'smart-home:check-openings';

    protected $description = 'Warn about doors and windows left open';

    public function handle(Openings $openings): int
    {
        $warned = $openings->checkLeftOpen();
        if ($warned > 0) {
            $this->line("{$warned} left open");
        }

        return self::SUCCESS;
    }
}
