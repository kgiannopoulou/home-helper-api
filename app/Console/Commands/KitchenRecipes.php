<?php

namespace App\Console\Commands;

use App\Kitchen\Recipes\RecipeLibrary;
use Illuminate\Console\Command;

/**
 * php artisan kitchen:recipes — load Home Helper's own recipes from
 * database/data/recipes.php (docs/kitchen.md#recipes). Safe to run again:
 * matched by slug.
 */
class KitchenRecipes extends Command
{
    protected $signature = 'kitchen:recipes';

    protected $description = 'Load or update Home Helper\'s own recipes';

    public function handle(): int
    {
        $this->info(RecipeLibrary::sync().' recipes loaded.');

        return self::SUCCESS;
    }
}
