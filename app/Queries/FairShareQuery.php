<?php

namespace App\Queries;

use App\Models\Household;
use Carbon\CarbonImmutable;

/**
 * Minutes of chores each member did in the last N days, and their share of the total.
 * Like the phone's fair share, it counts minutes, not chores: cleaning the oven isn't
 * the same as wiping a counter. Every member is listed, also with nothing done.
 */
class FairShareQuery extends InsightQuery
{
    public function __construct(Household $household, CarbonImmutable $today, protected int $days = 30)
    {
        parent::__construct($household, $today);
    }

    public function sql(): string
    {
        return <<<'SQL'
            WITH params AS (
                SELECT ? AS household_id, CAST(? AS DATE) AS today, ? AS days
            ),
            done AS (
                SELECT cc.user_id, SUM(cc.minutes) AS minutes, COUNT(*) AS chores
                FROM chore_completions cc
                JOIN params p ON cc.household_id = p.household_id
                WHERE cc.deleted_at IS NULL
                  AND cc.done_at >= p.today - INTERVAL (p.days - 1) DAY
                  AND cc.done_at < p.today + INTERVAL 1 DAY
                GROUP BY cc.user_id
            )
            SELECT u.id AS user_id,
                   u.name,
                   COALESCE(d.minutes, 0) AS minutes,
                   COALESCE(d.chores, 0) AS chores,
                   ROUND(COALESCE(d.minutes, 0) / NULLIF(SUM(COALESCE(d.minutes, 0)) OVER (), 0), 4) AS share
            FROM household_user hu
            JOIN params p ON hu.household_id = p.household_id
            JOIN users u ON u.id = hu.user_id
            LEFT JOIN done d ON d.user_id = u.id
            ORDER BY minutes DESC, u.name
            SQL;
    }

    protected function bindings(): array
    {
        return [$this->household->id, $this->today->toDateString(), $this->days];
    }

    /**
     * @return list<array{user_id: int, name: string, minutes: int, chores: int, share: float}>
     */
    public function get(): array
    {
        return array_map(fn (object $r) => [
            'user_id' => (int) $r->user_id,
            'name' => $r->name,
            'minutes' => (int) $r->minutes,
            'chores' => (int) $r->chores,
            'share' => (float) ($r->share ?? 0),
        ], $this->rows());
    }
}
