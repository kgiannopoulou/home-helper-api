<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // In production there is nothing to seed: people register and create their household
        if (app()->isProduction()) {
            Log::info('db:seed in production: no demo data seeded.');

            return;
        }

        $this->call(DemoSeeder::class);
    }
}
