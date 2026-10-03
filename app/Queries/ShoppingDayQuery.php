<?php

namespace App\Queries;

/**
 * Shops per weekday over the last 12 weeks, and the usual shopping day.
 * Same rules as usualShoppingDay() on the phone: a shop is a logged trip or
 * groceries of €15 or more; the usual day needs at least 3 shops, and at least
 * 2 and 40% of them on that weekday. Ties go to the earlier weekday (Sunday first).
 */
class ShoppingDayQuery extends InsightQuery
{
    public function sql(): string
    {
        return <<<'SQL'
            WITH params AS (
                SELECT ? AS household_id, CAST(? AS DATE) AS today
            ),
            shops AS (
                SELECT t.date
                FROM shopping_trips t
                JOIN params p ON t.household_id = p.household_id
                WHERE t.deleted_at IS NULL
                  AND t.date BETWEEN p.today - INTERVAL 84 DAY AND p.today
                -- UNION, not UNION ALL: a trip and its grocery expense are one shop
                UNION
                SELECT e.date
                FROM expenses e
                JOIN params p ON e.household_id = p.household_id
                WHERE e.deleted_at IS NULL
                  AND e.category = 'groceries'
                  AND e.amount >= 15
                  AND e.date BETWEEN p.today - INTERVAL 84 DAY AND p.today
            ),
            -- DAYOFWEEK is 1 = Sunday … 7 = Saturday; the phone counts 0 = Sunday
            shop_days AS (
                SELECT DAYOFWEEK(date) - 1 AS weekday FROM shops
            ),
            by_weekday AS (
                SELECT weekday,
                       COUNT(*) AS shops,
                       SUM(COUNT(*)) OVER () AS all_shops,
                       ROW_NUMBER() OVER (ORDER BY COUNT(*) DESC, weekday) AS rank_
                FROM shop_days
                GROUP BY weekday
            )
            SELECT b.weekday,
                   ELT(b.weekday + 1, 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday') AS name,
                   b.shops,
                   b.all_shops,
                   ROUND(b.shops / b.all_shops, 4) AS share,
                   -- Next time it comes round, today included
                   p.today + INTERVAL ((b.weekday - (DAYOFWEEK(p.today) - 1) + 7) % 7) DAY AS next_on,
                   (b.rank_ = 1 AND b.all_shops >= 3 AND b.shops >= 2 AND b.shops / b.all_shops >= 0.4) AS is_usual
            FROM by_weekday b
            CROSS JOIN params p
            ORDER BY b.rank_
            SQL;
    }

    protected function bindings(): array
    {
        return [$this->household->id, $this->today->toDateString()];
    }

    /**
     * @return array{usual: array{weekday: int, name: string, next_on: string, share: float}|null, weekdays: list<array{weekday: int, name: string, shops: int, share: float}>, shops: int}
     */
    public function get(): array
    {
        $rows = $this->rows();
        $usual = collect($rows)->firstWhere('is_usual', 1);

        return [
            'usual' => $usual ? [
                'weekday' => (int) $usual->weekday,
                'name' => $usual->name,
                'next_on' => $usual->next_on,
                'share' => (float) $usual->share,
            ] : null,
            'weekdays' => array_map(fn (object $r) => [
                'weekday' => (int) $r->weekday,
                'name' => $r->name,
                'shops' => (int) $r->shops,
                'share' => (float) $r->share,
            ], $rows),
            'shops' => (int) ($rows[0]->all_shops ?? 0),
        ];
    }
}
