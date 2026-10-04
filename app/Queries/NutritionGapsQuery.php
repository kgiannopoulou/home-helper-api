<?php

namespace App\Queries;

use App\Models\Household;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * One person's last 7 days of food and water against their targets, like
 * weekSummary() on the phone: averages over the days they logged anything (a
 * forgotten day isn't a fast), and a gap when the average is under 85% of target.
 *
 * The server doesn't have the phone's profile (age, height, goal), so targets
 * come from what it does have: protein 1.2 g and water 35 ml per kg of the latest
 * weight, and fibre 14 g per 1,000 kcal actually eaten. No kcal target, so no kcal gap.
 */
class NutritionGapsQuery extends InsightQuery
{
    public const GAP_BELOW = 0.85;

    public function __construct(Household $household, CarbonImmutable $today, protected User $user)
    {
        parent::__construct($household, $today);
    }

    public function sql(): string
    {
        return <<<'SQL'
            WITH params AS (
                SELECT ? AS household_id, ? AS user_id, CAST(? AS DATE) AS today
            ),
            food_days AS (
                SELECT f.date, SUM(f.kcal) AS kcal, SUM(f.protein) AS protein, SUM(f.fiber) AS fiber
                FROM food_entries f
                JOIN params p ON f.household_id = p.household_id AND f.user_id = p.user_id
                WHERE f.deleted_at IS NULL
                  AND f.date BETWEEN p.today - INTERVAL 6 DAY AND p.today
                GROUP BY f.date
            ),
            water_days AS (
                SELECT w.date, SUM(w.ml) AS ml
                FROM water_entries w
                JOIN params p ON w.household_id = p.household_id AND w.user_id = p.user_id
                WHERE w.deleted_at IS NULL
                  AND w.date BETWEEN p.today - INTERVAL 6 DAY AND p.today
                GROUP BY w.date
            ),
            latest_weight AS (
                SELECT w.kg
                FROM weights w
                JOIN params p ON w.household_id = p.household_id AND w.user_id = p.user_id
                WHERE w.deleted_at IS NULL AND w.date <= p.today
                ORDER BY w.date DESC
                LIMIT 1
            ),
            averages AS (
                SELECT (SELECT COUNT(*) FROM food_days) AS logged_days,
                       (SELECT ROUND(AVG(kcal)) FROM food_days) AS kcal,
                       (SELECT ROUND(AVG(protein), 1) FROM food_days) AS protein,
                       (SELECT ROUND(AVG(fiber), 1) FROM food_days) AS fiber,
                       (SELECT COUNT(*) FROM water_days) AS water_days,
                       (SELECT ROUND(AVG(ml)) FROM water_days) AS water_ml,
                       (SELECT kg FROM latest_weight) AS kg
            )
            SELECT a.*,
                   ROUND(a.kg * 1.2) AS protein_target,
                   ROUND(a.kcal / 1000 * 14) AS fiber_target,
                   ROUND(a.kg * 35 / 50) * 50 AS water_target
            FROM averages a
            SQL;
    }

    protected function bindings(): array
    {
        return [$this->household->id, $this->user->id, $this->today->toDateString()];
    }

    /**
     * @return array{logged_days: int, water_days: int, average: array{kcal: int|null, protein: float|null, fiber: float|null, water_ml: int|null}, targets: array{protein: int|null, fiber: int|null, water_ml: int|null}, gaps: list<array{key: string, average: float, target: int, ratio: float}>}
     */
    public function get(): array
    {
        $r = $this->rows()[0];
        $num = fn ($v) => $v === null ? null : (float) $v;
        $int = fn ($v) => $v === null ? null : (int) $v;

        $gaps = [];
        $check = function (string $key, ?float $average, ?int $target, bool $logged) use (&$gaps) {
            if (! $logged || $average === null || ! $target) {
                return;
            }
            $ratio = round($average / $target, 2);
            if ($ratio < self::GAP_BELOW) {
                $gaps[] = ['key' => $key, 'average' => $average, 'target' => $target, 'ratio' => $ratio];
            }
        };
        $check('protein', $num($r->protein), $int($r->protein_target), $r->logged_days > 0);
        $check('fiber', $num($r->fiber), $int($r->fiber_target), $r->logged_days > 0);
        $check('water', $num($r->water_ml), $int($r->water_target), $r->water_days > 0);

        return [
            'logged_days' => (int) $r->logged_days,
            'water_days' => (int) $r->water_days,
            'average' => ['kcal' => $int($r->kcal), 'protein' => $num($r->protein), 'fiber' => $num($r->fiber), 'water_ml' => $int($r->water_ml)],
            'targets' => ['protein' => $int($r->protein_target), 'fiber' => $int($r->fiber_target), 'water_ml' => $int($r->water_target)],
            'gaps' => $gaps,
        ];
    }
}
