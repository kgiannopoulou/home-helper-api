<?php

namespace Database\Seeders;

use App\Models\Household;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies the demo household many times, so EXPLAIN ANALYZE runs on tables of a
 * realistic size instead of one household's few hundred rows.
 *
 *   php artisan db:seed --class=BenchmarkSeeder
 *
 * It's all done in MySQL: a recursive CTE counts 1…N and INSERT … SELECT copies
 * each table once. A copy's id is the original's first 20 characters plus the
 * copy number, so foreign keys between copied rows still match.
 */
class BenchmarkSeeder extends Seeder
{
    public const COPIES = 500;

    /** Tables to copy, parents first, so foreign keys are satisfied */
    private const TABLES = [
        'households', 'recurring_bills', 'budgets', 'expenses', 'shopping_trips',
        'inventory_items', 'purchases', 'item_prices', 'rooms', 'chores', 'chore_completions',
    ];

    /** Columns that point at copied rows and must be renamed with them */
    private const COPIED_KEYS = [
        'id', 'household_id', 'recurring_bill_id', 'shopping_trip_id', 'inventory_item_id', 'room_id', 'chore_id',
    ];

    public function run(): void
    {
        $demo = Household::where('name', 'Patission 12')->orderBy('created_at')->firstOrFail();

        DB::statement('SET SESSION cte_max_recursion_depth = '.(self::COPIES + 1));

        foreach (self::TABLES as $table) {
            $columns = array_values(array_diff(Schema::getColumnListing($table), $this->generated($table)));
            $select = array_map(fn (string $c) => match (true) {
                in_array($c, self::COPIED_KEYS, true) => "IF($c IS NULL, NULL, CONCAT(LEFT($c, 20), LPAD(n.i, 6, '0')))",
                $table === 'households' && $c === 'name' => "CONCAT(name, ' #', n.i)",
                default => $c,
            }, $columns);
            $owner = $table === 'households' ? 'id' : 'household_id';

            $inserted = DB::affectingStatement(
                'INSERT INTO '.$table.' ('.implode(', ', $columns).')
                 WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < ?)
                 SELECT '.implode(', ', $select)." FROM $table CROSS JOIN n WHERE $owner = ?",
                [self::COPIES, $demo->id],
            );
            $this->command?->info(sprintf('%-18s +%s rows', $table, number_format($inserted)));
        }

        DB::statement('ANALYZE TABLE '.implode(', ', self::TABLES));
    }

    /**
     * @return list<string>
     */
    private function generated(string $table): array
    {
        return array_column(DB::select(
            "SELECT column_name AS name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND extra LIKE '%GENERATED%'",
            [$table],
        ), 'name');
    }
}
