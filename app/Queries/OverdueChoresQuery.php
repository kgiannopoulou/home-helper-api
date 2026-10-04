<?php

namespace App\Queries;

/**
 * Chores past their next due day, the most overdue first. A chore is due
 * every_days after the last time it was done; chores never done aren't counted,
 * because there is nothing to be late from.
 */
class OverdueChoresQuery extends InsightQuery
{
    public function sql(): string
    {
        return <<<'SQL'
            WITH params AS (
                SELECT ? AS household_id, CAST(? AS DATE) AS today
            ),
            last_done AS (
                SELECT cc.chore_id, DATE(MAX(cc.done_at)) AS day
                FROM chore_completions cc
                JOIN params p ON cc.household_id = p.household_id
                WHERE cc.deleted_at IS NULL
                  AND cc.done_at < p.today + INTERVAL 1 DAY
                GROUP BY cc.chore_id
            )
            SELECT c.id,
                   c.name,
                   r.name AS room,
                   c.every_days,
                   l.day AS last_done,
                   DATEDIFF(p.today, l.day) - c.every_days AS days_overdue
            FROM chores c
            JOIN params p ON c.household_id = p.household_id
            JOIN last_done l ON l.chore_id = c.id
            JOIN rooms r ON r.id = c.room_id AND r.deleted_at IS NULL
            WHERE c.deleted_at IS NULL
              AND DATEDIFF(p.today, l.day) > c.every_days
            ORDER BY days_overdue DESC, r.name, c.name
            SQL;
    }

    protected function bindings(): array
    {
        return [$this->household->id, $this->today->toDateString()];
    }

    /**
     * @return list<array{id: string, name: string, room: string, every_days: int, last_done: string, days_overdue: int}>
     */
    public function get(): array
    {
        return array_map(fn (object $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'room' => $r->room,
            'every_days' => (int) $r->every_days,
            'last_done' => $r->last_done,
            'days_overdue' => (int) $r->days_overdue,
        ], $this->rows());
    }
}
