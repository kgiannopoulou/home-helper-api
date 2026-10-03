<?php

namespace App\Queries;

/**
 * Where this month's spending will end. Same rules as budgetForecast() on the phone:
 * - bills (recurring expenses) happen once, so only day-to-day spending is extrapolated
 * - with past months, it blends the pace with "past months spent X by this day and Y in all",
 *   trusting the pace more the further into the month it is
 * - without history, there is no forecast before the 5th
 */
class BudgetForecastQuery extends InsightQuery
{
    public function sql(): string
    {
        return <<<'SQL'
            WITH params AS (
                SELECT ? AS household_id, CAST(? AS DATE) AS today
            ),
            dates AS (
                SELECT household_id,
                       today,
                       DAY(today) AS day,
                       DAY(LAST_DAY(today)) AS days_in_month,
                       today - INTERVAL (DAY(today) - 1) DAY AS month_start
                FROM params
            ),
            this_month AS (
                SELECT COALESCE(SUM(e.amount), 0) AS total,
                       COALESCE(SUM(CASE WHEN e.source = 'recurring' THEN e.amount END), 0) AS bills
                FROM dates d
                LEFT JOIN expenses e
                       ON e.household_id = d.household_id
                      AND e.deleted_at IS NULL
                      AND e.date BETWEEN d.month_start AND LAST_DAY(d.today)
            ),
            -- The last 3 months that have any spending: all of it, and how much by this day of the month
            past_months AS (
                SELECT DATE_FORMAT(e.date, '%Y-%m') AS month,
                       SUM(e.amount) AS total,
                       SUM(CASE WHEN DAY(e.date) <= LEAST(d.day, DAY(LAST_DAY(e.date))) THEN e.amount ELSE 0 END) AS by_this_day
                FROM expenses e
                JOIN dates d ON e.household_id = d.household_id
                WHERE e.deleted_at IS NULL
                  AND e.date >= d.month_start - INTERVAL 3 MONTH
                  AND e.date < d.month_start
                GROUP BY month
            ),
            history AS (
                SELECT COUNT(*) AS months,
                       ROUND(AVG(by_this_day), 2) AS usual_by_now,
                       ROUND(AVG(total), 2) AS average_month
                FROM past_months
            ),
            budget AS (
                SELECT MAX(b.amount) AS amount
                FROM budgets b
                JOIN params p ON b.household_id = p.household_id
                WHERE b.deleted_at IS NULL AND b.period = 'month' AND b.category IS NULL
            ),
            pace AS (
                SELECT t.total,
                       t.bills,
                       ROUND(t.bills + (t.total - t.bills) / d.day * d.days_in_month, 2) AS projected,
                       d.day,
                       d.days_in_month,
                       h.months,
                       h.usual_by_now,
                       h.average_month,
                       b.amount AS budget
                FROM this_month t
                CROSS JOIN dates d
                CROSS JOIN history h
                CROSS JOIN budget b
            ),
            forecast AS (
                SELECT pace.*,
                       CASE
                           WHEN total <= 0 THEN NULL
                           -- day/days_in_month of the pace, the rest from history
                           WHEN usual_by_now > 0 AND average_month > 0 THEN GREATEST(total, ROUND(
                               (day * projected + (days_in_month - day) * total * average_month / usual_by_now) / days_in_month, 2))
                           WHEN day < 5 THEN NULL
                           ELSE GREATEST(total, projected)
                       END AS forecast
                FROM pace
            )
            SELECT total, bills, projected, day, days_in_month, months, usual_by_now, average_month, budget, forecast,
                   forecast - budget AS over_budget
            FROM forecast
            SQL;
    }

    protected function bindings(): array
    {
        return [$this->household->id, $this->today->toDateString()];
    }

    /**
     * @return array{forecast: float|null, budget: float|null, over_budget: float|null, spent: float, bills: float, projected_from_pace: float, day: int, days_in_month: int, history: array{months: int, usual_by_this_day: float|null, average_month: float|null}}
     */
    public function get(): array
    {
        $r = $this->rows()[0];
        $money = fn ($v) => $v === null ? null : (float) $v;

        return [
            'forecast' => $money($r->forecast),
            'budget' => $money($r->budget),
            'over_budget' => $money($r->over_budget),
            'spent' => (float) $r->total,
            'bills' => (float) $r->bills,
            'projected_from_pace' => (float) $r->projected,
            'day' => (int) $r->day,
            'days_in_month' => (int) $r->days_in_month,
            'history' => [
                'months' => (int) $r->months,
                'usual_by_this_day' => $money($r->usual_by_now),
                'average_month' => $money($r->average_month),
            ],
        ];
    }
}
