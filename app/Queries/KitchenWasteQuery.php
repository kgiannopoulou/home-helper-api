<?php

namespace App\Queries;

use App\Models\Household;
use Carbon\CarbonImmutable;

/**
 * What the kitchen threw away (docs/kitchen.md#waste): everything recorded as
 * wasted or expired in the last $days, per item, with what it cost.
 *
 * The cost is an estimate: the share of a pack that went, times what the
 * last pack cost (purchases, newest first by a window function). An item
 * never bought through Home Helper has no price, and says so.
 */
class KitchenWasteQuery extends InsightQuery
{
    public function __construct(Household $household, CarbonImmutable $today, private int $days = 30)
    {
        parent::__construct($household, $today);
    }

    public function sql(): string
    {
        return <<<'SQL'
            WITH params AS (
                SELECT ? AS household_id, ? AS since
            ),
            wasted AS (
                SELECT m.inventory_item_id, m.unit, -SUM(m.amount) AS amount, COUNT(*) AS times, MAX(m.occurred_at) AS last_at
                FROM kitchen_movements m
                JOIN params p ON m.household_id = p.household_id
                WHERE m.kind IN ('wasted', 'expired') AND m.occurred_at >= p.since
                GROUP BY m.inventory_item_id, m.unit
            ),
            last_price AS (
                SELECT pu.inventory_item_id, pu.price,
                       ROW_NUMBER() OVER (PARTITION BY pu.inventory_item_id ORDER BY pu.bought_on DESC, pu.created_at DESC) AS rn
                FROM purchases pu
                JOIN wasted w ON w.inventory_item_id = pu.inventory_item_id
                WHERE pu.deleted_at IS NULL AND pu.price IS NOT NULL
            )
            SELECT i.id, i.name, i.ingredient, w.amount, w.unit, w.times, w.last_at,
                   lp.price AS pack_price,
                   COALESCE(i.pack_amount, i.full_amount) AS pack_amount,
                   ROUND(LEAST(w.amount / NULLIF(COALESCE(i.pack_amount, i.full_amount), 0), 20) * lp.price, 2) AS value
            FROM wasted w
            JOIN inventory_items i ON i.id = w.inventory_item_id
            LEFT JOIN last_price lp ON lp.inventory_item_id = w.inventory_item_id AND lp.rn = 1
            WHERE w.amount > 0
            ORDER BY value IS NULL, value DESC, w.amount DESC
            SQL;
    }

    protected function bindings(): array
    {
        return [
            $this->household->id,
            $this->today->subDays($this->days)->startOfDay()->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{days: int, items: list<array{id: string, name: string, ingredient: string|null, amount: float, unit: string, times: int, value: float|null}>, value: float, priced: int}
     */
    public function get(): array
    {
        $items = array_map(function (object $row) {
            /** @var array{id: string, name: string, ingredient: string|null, amount: string, unit: string, times: int|string, value: string|null} $r */
            $r = (array) $row;

            return [
                'id' => $r['id'],
                'name' => $r['name'],
                'ingredient' => $r['ingredient'],
                'amount' => (float) $r['amount'],
                'unit' => $r['unit'],
                'times' => (int) $r['times'],
                'value' => $r['value'] === null ? null : (float) $r['value'],
            ];
        }, $this->rows());

        return [
            'days' => $this->days,
            'items' => $items,
            'value' => round(array_sum(array_map(fn ($i) => $i['value'] ?? 0, $items)), 2),
            'priced' => count(array_filter($items, fn ($i) => $i['value'] !== null)),
        ];
    }
}
