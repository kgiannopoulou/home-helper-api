<?php

namespace App\Queries;

use App\Models\Household;
use Carbon\CarbonImmutable;

/**
 * Spending per Monday week and category for the last N weeks, each next to the
 * same category the week before.
 */
class WeeklySpendingQuery extends InsightQuery
{
    public function __construct(Household $household, CarbonImmutable $today, protected int $weeks = 8)
    {
        parent::__construct($household, $today);
    }

    public function sql(): string
    {
        return <<<'SQL'
            -- The weeks asked for, plus the one before so the first week has something to compare with.
            -- The household is a plain value, not a join to a params CTE, and the view is read once:
            -- only then does MySQL push the conditions down into the grouped view, so it reads one
            -- household's rows through the index instead of grouping every household's expenses.
            WITH weeks AS (
                SELECT v.week_start, v.category, v.total, v.expenses
                FROM v_weekly_spending v
                WHERE v.household_id = ?
                  AND v.week_start BETWEEN CAST(? AS DATE) - INTERVAL 7 DAY AND CAST(? AS DATE)
            ),
            compared AS (
                SELECT weeks.*,
                       -- The category's previous row, if it is really the week before (not an older one)
                       CASE WHEN LAG(week_start) OVER w = week_start - INTERVAL 7 DAY THEN LAG(total) OVER w END AS previous_total
                FROM weeks
                WINDOW w AS (PARTITION BY category ORDER BY week_start)
            )
            SELECT week_start,
                   category,
                   total,
                   expenses,
                   previous_total,
                   ROUND((total - previous_total) / previous_total * 100) AS change_pct
            FROM compared
            WHERE week_start >= CAST(? AS DATE)
            ORDER BY week_start, total DESC, category
            SQL;
    }

    protected function bindings(): array
    {
        $lastWeek = $this->today->startOfWeek();
        $firstWeek = $lastWeek->subWeeks($this->weeks - 1)->toDateString();

        return [$this->household->id, $firstWeek, $lastWeek->toDateString(), $firstWeek];
    }

    /**
     * @return list<array{week_start: string, category: string, total: float, expenses: int, previous_total: float|null, change_pct: int|null}>
     */
    public function get(): array
    {
        return array_map(fn (object $r) => [
            'week_start' => $r->week_start,
            'category' => $r->category,
            'total' => (float) $r->total,
            'expenses' => (int) $r->expenses,
            'previous_total' => $r->previous_total === null ? null : (float) $r->previous_total,
            'change_pct' => $r->change_pct === null ? null : (int) $r->change_pct,
        ], $this->rows());
    }
}
