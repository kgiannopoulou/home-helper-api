<?php

namespace App\Console\Commands;

use App\Models\SmartHomeConnection;
use App\SmartHome\Discovery;
use App\SmartHome\HomeAssistantError;
use App\SmartHome\Readings;
use Illuminate\Console\Command;

/**
 * php artisan smart-home:sync — ask each Home Assistant for every state
 * (docs/smart-home.md#discovery-and-sync). Scheduled every minute: it finds
 * new and removed devices, and it is the fallback for state changes when
 * the webhook isn't set up or a push was lost.
 */
class SmartHomeSync extends Command
{
    protected $signature = 'smart-home:sync {--household= : only this household}';

    protected $description = 'Discover devices and refresh their states from Home Assistant';

    public function handle(Discovery $discovery, Readings $readings): int
    {
        $connections = SmartHomeConnection::query()
            ->when($this->option('household'), fn ($q, $id) => $q->where('household_id', $id))
            ->get();
        $failed = 0;

        foreach ($connections as $connection) {
            try {
                $r = $discovery->sync($connection);
                $readings->topUp($connection->household_id);
                $this->line("{$connection->household_id}: {$r['found']} devices, {$r['new']} new, {$r['removed']} removed, {$r['changed']} changed");
            } catch (HomeAssistantError $e) {
                $failed++;
                $this->warn("{$connection->household_id}: {$e->kind}: {$e->getMessage()}");
            }
        }

        // A Pi that's off is the household's problem, not a failed scheduled task
        return $failed > 0 && $failed === $connections->count() && $this->option('household') ? self::FAILURE : self::SUCCESS;
    }
}
