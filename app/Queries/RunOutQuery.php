<?php

namespace App\Queries;

/**
 * When each kitchen item will run out, from how often it has been bought.
 * Same rule as predictRunOut() on the phone: at least 3 purchase days, the
 * average gap between them, counted on from the last one.
 */
class RunOutQuery extends InsightQuery
{
    public function sql(): string
    {
        return <<<'SQL'
            WITH params AS (
                SELECT ? AS household_id, CAST(? AS DATE) AS today
            ),
            -- One row per item and day bought: two purchases on one day count once
            days AS (
                SELECT DISTINCT pu.inventory_item_id, pu.bought_on
                FROM purchases pu
                JOIN params p ON pu.household_id = p.household_id
                WHERE pu.deleted_at IS NULL
            ),
            gaps AS (
                SELECT inventory_item_id,
                       bought_on,
                       DATEDIFF(bought_on, LAG(bought_on) OVER (PARTITION BY inventory_item_id ORDER BY bought_on)) AS gap_days
                FROM days
            ),
            rhythm AS (
                SELECT inventory_item_id,
                       MAX(bought_on) AS last_bought,
                       COUNT(*) AS times_bought,
                       GREATEST(1, ROUND(AVG(gap_days))) AS every_days
                FROM gaps
                GROUP BY inventory_item_id
                HAVING COUNT(gap_days) >= 2
            )
            SELECT i.id,
                   i.name,
                   i.level,
                   r.last_bought,
                   r.times_bought,
                   r.every_days,
                   r.last_bought + INTERVAL r.every_days DAY AS runs_out_on,
                   DATEDIFF(r.last_bought + INTERVAL r.every_days DAY, p.today) AS days_left
            FROM rhythm r
            JOIN inventory_items i ON i.id = r.inventory_item_id AND i.deleted_at IS NULL
            CROSS JOIN params p
            ORDER BY runs_out_on, i.name
            SQL;
    }

    protected function bindings(): array
    {
        return [$this->household->id, $this->today->toDateString()];
    }

    /**
     * @return list<array{id: string, name: string, level: string, last_bought: string, times_bought: int, every_days: int, runs_out_on: string, days_left: int}>
     */
    public function get(): array
    {
        return array_map(fn (object $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'level' => $r->level,
            'last_bought' => $r->last_bought,
            'times_bought' => (int) $r->times_bought,
            'every_days' => (int) $r->every_days,
            'runs_out_on' => $r->runs_out_on,
            'days_left' => (int) $r->days_left,
        ], $this->rows());
    }
}
