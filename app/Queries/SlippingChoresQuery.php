<?php

namespace App\Queries;

/**
 * Chores whose last 3 gaps all ran at least 30% late (or 30% early), with a
 * better frequency. Same rules as learnFrequency() on the phone:
 * - a long overdue stretch right now counts as a late gap
 * - it moves one step along the usual frequencies, and only if that is closer to what you really do
 * - not for chores you fixed, nor again within 3 cycles of the last change
 */
class SlippingChoresQuery extends InsightQuery
{
    public function sql(): string
    {
        return <<<'SQL'
            WITH params AS (
                SELECT ? AS household_id, CAST(? AS DATE) AS today
            ),
            frequencies (days) AS (
                SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 7
                UNION ALL SELECT 14 UNION ALL SELECT 30 UNION ALL SELECT 90 UNION ALL SELECT 180
            ),
            candidates AS (
                SELECT c.id, c.name, c.room_id, c.every_days
                FROM chores c
                JOIN params p ON c.household_id = p.household_id
                WHERE c.deleted_at IS NULL
                  AND NOT c.fixed_frequency
                  AND (c.learned_on IS NULL OR DATEDIFF(p.today, c.learned_on) >= c.every_days * 3)
            ),
            done_days AS (
                SELECT DISTINCT cc.chore_id, DATE(cc.done_at) AS day
                FROM chore_completions cc
                JOIN candidates c ON c.id = cc.chore_id
                CROSS JOIN params p
                WHERE cc.deleted_at IS NULL
                  AND cc.done_at < p.today + INTERVAL 1 DAY
            ),
            -- Today joins the list when the chore is overdue by more than 30%
            points AS (
                SELECT chore_id, day FROM done_days
                UNION ALL
                SELECT last.chore_id, p.today
                FROM (SELECT chore_id, MAX(day) AS day FROM done_days GROUP BY chore_id) last
                JOIN candidates c ON c.id = last.chore_id
                CROSS JOIN params p
                WHERE DATEDIFF(p.today, last.day) > c.every_days * 1.3
            ),
            gaps AS (
                SELECT chore_id,
                       day,
                       DATEDIFF(day, LAG(day) OVER (PARTITION BY chore_id ORDER BY day)) AS gap,
                       ROW_NUMBER() OVER (PARTITION BY chore_id ORDER BY day DESC) AS newest
                FROM points
            ),
            recent AS (
                SELECT chore_id,
                       MIN(gap) AS shortest,
                       MAX(gap) AS longest,
                       -- The median of three: the one that is neither the shortest nor the longest
                       SUM(gap) - MIN(gap) - MAX(gap) AS typical,
                       GROUP_CONCAT(gap ORDER BY day SEPARATOR ',') AS gaps
                FROM gaps
                WHERE newest <= 3 AND gap IS NOT NULL
                GROUP BY chore_id
                HAVING COUNT(*) = 3
            ),
            trends AS (
                SELECT c.*,
                       r.typical,
                       r.gaps,
                       CASE
                           WHEN r.shortest >= c.every_days * 1.3 THEN 'late'
                           WHEN r.longest <= c.every_days * 0.7 THEN 'early'
                       END AS trend
                FROM recent r
                JOIN candidates c ON c.id = r.chore_id
            ),
            suggestions AS (
                SELECT t.*,
                       CASE t.trend
                           WHEN 'late' THEN (SELECT MIN(f.days) FROM frequencies f WHERE f.days > t.every_days)
                           ELSE (SELECT MAX(f.days) FROM frequencies f WHERE f.days < t.every_days)
                       END AS suggested_every_days
                FROM trends t
                WHERE t.trend IS NOT NULL
            )
            SELECT s.id, s.name, rooms.name AS room, s.every_days, s.trend, s.typical AS typical_days, s.gaps, s.suggested_every_days
            FROM suggestions s
            JOIN rooms ON rooms.id = s.room_id
            WHERE s.suggested_every_days IS NOT NULL
              AND ABS(s.suggested_every_days - s.typical) < ABS(s.every_days - s.typical)
            ORDER BY s.trend DESC, rooms.name, s.name
            SQL;
    }

    protected function bindings(): array
    {
        return [$this->household->id, $this->today->toDateString()];
    }

    /**
     * @return list<array{id: string, name: string, room: string, every_days: int, trend: string, typical_days: int, gaps: list<int>, suggested_every_days: int}>
     */
    public function get(): array
    {
        return array_map(fn (object $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'room' => $r->room,
            'every_days' => (int) $r->every_days,
            'trend' => $r->trend,
            'typical_days' => (int) $r->typical_days,
            'gaps' => array_map('intval', explode(',', $r->gaps)),
            'suggested_every_days' => (int) $r->suggested_every_days,
        ], $this->rows());
    }
}
