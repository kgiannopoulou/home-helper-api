<?php

namespace App\Queries;

use App\Models\Household;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * One person's sleep, steps and workouts per Monday week, for the last N weeks.
 * A recursive CTE makes the weeks, so a week with nothing logged is still there
 * (as zeros and nulls) instead of missing from the chart. Under WITH RECURSIVE a
 * CTE can't share a table's name (it would mean itself), hence sleep_weeks and so on.
 */
class HealthWeeksQuery extends InsightQuery
{
    public function __construct(Household $household, CarbonImmutable $today, protected User $user, protected int $weeks = 8)
    {
        parent::__construct($household, $today);
    }

    public function sql(): string
    {
        return <<<'SQL'
            WITH RECURSIVE params AS (
                SELECT ? AS household_id, ? AS user_id, CAST(? AS DATE) AS first_week, CAST(? AS DATE) AS today
            ),
            weeks (week_start) AS (
                SELECT first_week FROM params
                UNION ALL
                SELECT week_start + INTERVAL 7 DAY FROM weeks, params WHERE week_start + INTERVAL 7 DAY <= params.today
            ),
            sleep_weeks AS (
                SELECT DATE_SUB(s.date, INTERVAL WEEKDAY(s.date) DAY) AS week_start,
                       AVG(s.hours) AS hours, AVG(s.quality) AS quality, COUNT(*) AS nights
                FROM sleep_entries s
                JOIN params p ON s.household_id = p.household_id AND s.user_id = p.user_id
                WHERE s.deleted_at IS NULL AND s.date BETWEEN p.first_week AND p.today
                GROUP BY week_start
            ),
            step_weeks AS (
                SELECT DATE_SUB(c.date, INTERVAL WEEKDAY(c.date) DAY) AS week_start,
                       SUM(c.steps) AS total, COUNT(*) AS days
                FROM step_counts c
                JOIN params p ON c.household_id = p.household_id AND c.user_id = p.user_id
                WHERE c.deleted_at IS NULL AND c.date BETWEEN p.first_week AND p.today
                GROUP BY week_start
            ),
            workout_weeks AS (
                SELECT DATE_SUB(w.date, INTERVAL WEEKDAY(w.date) DAY) AS week_start,
                       COUNT(*) AS sessions, SUM(w.minutes) AS minutes, SUM(w.kcal) AS kcal
                FROM workouts w
                JOIN params p ON w.household_id = p.household_id AND w.user_id = p.user_id
                WHERE w.deleted_at IS NULL AND w.date BETWEEN p.first_week AND p.today
                GROUP BY week_start
            )
            SELECT wk.week_start,
                   ROUND(sl.hours, 1) AS sleep_hours,
                   ROUND(sl.quality, 1) AS sleep_quality,
                   COALESCE(sl.nights, 0) AS nights,
                   COALESCE(st.total, 0) AS steps,
                   ROUND(st.total / st.days) AS steps_per_day,
                   COALESCE(wo.sessions, 0) AS workouts,
                   COALESCE(wo.minutes, 0) AS workout_minutes
            FROM weeks wk
            LEFT JOIN sleep_weeks sl ON sl.week_start = wk.week_start
            LEFT JOIN step_weeks st ON st.week_start = wk.week_start
            LEFT JOIN workout_weeks wo ON wo.week_start = wk.week_start
            ORDER BY wk.week_start
            SQL;
    }

    protected function bindings(): array
    {
        $firstWeek = $this->today->startOfWeek()->subWeeks($this->weeks - 1);

        return [$this->household->id, $this->user->id, $firstWeek->toDateString(), $this->today->toDateString()];
    }

    /**
     * @return list<array{week_start: string, sleep_hours: float|null, sleep_quality: float|null, nights: int, steps: int, steps_per_day: int|null, workouts: int, workout_minutes: int}>
     */
    public function get(): array
    {
        return array_map(fn (object $r) => [
            'week_start' => $r->week_start,
            'sleep_hours' => $r->sleep_hours === null ? null : (float) $r->sleep_hours,
            'sleep_quality' => $r->sleep_quality === null ? null : (float) $r->sleep_quality,
            'nights' => (int) $r->nights,
            'steps' => (int) $r->steps,
            'steps_per_day' => $r->steps_per_day === null ? null : (int) $r->steps_per_day,
            'workouts' => (int) $r->workouts,
            'workout_minutes' => (int) $r->workout_minutes,
        ], $this->rows());
    }
}
