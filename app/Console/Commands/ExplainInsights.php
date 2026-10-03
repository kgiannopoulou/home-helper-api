<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Queries\BudgetForecastQuery;
use App\Queries\InsightQuery;
use App\Queries\RunOutQuery;
use App\Queries\ShoppingDayQuery;
use App\Queries\SlippingChoresQuery;
use App\Queries\WeeklySpendingQuery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Saves EXPLAIN ANALYZE for every insight query, for the demo household, to
 * docs/explain/<query>.<label>.txt. Run it on benchmark data
 * (db:seed --class=BenchmarkSeeder) before and after changing indexes.
 */
#[Signature('insights:explain {label=after : Name for this run, e.g. before or after} {--date= : Pretend today is this day}')]
#[Description('Save EXPLAIN ANALYZE output for the insight queries')]
class ExplainInsights extends Command
{
    public function handle(): int
    {
        $home = Household::where('name', 'Patission 12')->orderBy('created_at')->firstOrFail();
        $today = CarbonImmutable::parse($this->option('date') ?? 'today');

        /** @var array<string, InsightQuery> $queries */
        $queries = [
            'weekly-spending' => new WeeklySpendingQuery($home, $today),
            'run-out' => new RunOutQuery($home, $today),
            'slipping-chores' => new SlippingChoresQuery($home, $today),
            'shopping-day' => new ShoppingDayQuery($home, $today),
            'budget-forecast' => new BudgetForecastQuery($home, $today),
        ];

        File::ensureDirectoryExists(base_path('docs/explain'));

        foreach ($queries as $name => $query) {
            $query->explainAnalyze(); // warm the buffer pool, so both runs start alike
            $plan = $query->explainAnalyze();
            File::put(base_path("docs/explain/$name.{$this->argument('label')}.txt"), $plan);

            preg_match('/actual time=[\d.]+\.\.([\d.]+)/', $plan, $m);
            $this->line(sprintf('%-16s %8s ms', $name, $m[1] ?? '?'));
        }

        return self::SUCCESS;
    }
}
