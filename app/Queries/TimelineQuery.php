<?php

namespace App\Queries;

use App\Models\Household;
use App\Models\User;
use App\Support\HomeTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The household's activity timeline (docs/memory.md#activity-timeline): what
 * happened, newest first, from every module, in one UNION ALL.
 *
 * Nothing is copied into a new table: each branch reads the module's own rows,
 * so the timeline can't drift from the data and needs no backfill. Some rows
 * have a time (a chore done at 18:40, stored in UTC) and some only a day (an
 * expense on 3 Nov); day-only rows sort as if at noon at home.
 *
 * Private things (a private note or fact, your own assistant actions) are only
 * in the viewer's timeline.
 */
class TimelineQuery extends InsightQuery
{
    /** kind => one SELECT over the params CTE `p`, with the columns of every branch */
    private const BRANCHES = [
        'expense' => <<<'SQL'
            SELECT 'expense' AS kind, e.id AS ref_id, NULL AS at, e.date AS on_date,
                   CAST(e.date AS DATETIME) + INTERVAL 10 HOUR AS sort_at,
                   COALESCE(e.note, e.category) AS title, e.category AS detail, e.amount, u.name AS who
            FROM expenses e JOIN params p ON e.household_id = p.household_id
            LEFT JOIN users u ON u.id = e.user_id
            WHERE e.deleted_at IS NULL AND e.date BETWEEN p.from_date AND p.to_date
            SQL,
        'shopping_trip' => <<<'SQL'
            SELECT 'shopping_trip', t.id, NULL, t.date, CAST(t.date AS DATETIME) + INTERVAL 10 HOUR,
                   COALESCE(t.store, 'Shopping trip'), CONCAT(t.item_count, ' items'), t.total, u.name
            FROM shopping_trips t JOIN params p ON t.household_id = p.household_id
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.deleted_at IS NULL AND t.date BETWEEN p.from_date AND p.to_date
            SQL,
        'chore' => <<<'SQL'
            SELECT 'chore', cc.id, cc.done_at, NULL, cc.done_at,
                   CONCAT(r.name, ': ', c.name), CONCAT(cc.minutes, ' min'), NULL, u.name
            FROM chore_completions cc JOIN params p ON cc.household_id = p.household_id
            JOIN chores c ON c.id = cc.chore_id
            JOIN rooms r ON r.id = c.room_id
            LEFT JOIN users u ON u.id = cc.user_id
            WHERE cc.deleted_at IS NULL AND cc.done_at >= p.from_utc AND cc.done_at < p.to_utc
            SQL,
        'todo' => <<<'SQL'
            SELECT 'todo', t.id, t.done_at, NULL, t.done_at, t.title, 'done', NULL, u.name
            FROM todos t JOIN params p ON t.household_id = p.household_id
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.deleted_at IS NULL AND t.done_at >= p.from_utc AND t.done_at < p.to_utc
            SQL,
        'plant_care' => <<<'SQL'
            SELECT 'plant_care', l.id, l.performed_at, NULL, l.performed_at,
                   CONCAT(UPPER(LEFT(l.type, 1)), SUBSTRING(l.type, 2), ' ', pl.name),
                   CONCAT_WS(' · ', IF(l.amount_ml IS NULL, NULL, CONCAT(l.amount_ml, ' ml')), l.notes), NULL, u.name
            FROM plant_care_logs l JOIN params p ON l.household_id = p.household_id
            JOIN plants pl ON pl.id = l.plant_id
            LEFT JOIN users u ON u.id = l.user_id
            WHERE l.deleted_at IS NULL AND l.performed_at >= p.from_utc AND l.performed_at < p.to_utc
            SQL,
        'event' => <<<'SQL'
            SELECT 'event', ev.id, ev.starts_at, NULL, ev.starts_at, ev.title, ev.location, NULL, u.name
            FROM events ev JOIN params p ON ev.household_id = p.household_id
            LEFT JOIN users u ON u.id = ev.user_id
            WHERE ev.deleted_at IS NULL AND ev.starts_at >= p.from_utc AND ev.starts_at < p.to_utc
            SQL,
        'admin' => <<<'SQL'
            SELECT 'admin', a.id, a.done_at, NULL, a.done_at, a.title, a.kind, a.amount, NULL
            FROM admin_items a JOIN params p ON a.household_id = p.household_id
            WHERE a.deleted_at IS NULL AND a.done_at >= p.from_utc AND a.done_at < p.to_utc
            SQL,
        'note' => <<<'SQL'
            SELECT 'note', n.id, n.created_at, NULL, n.created_at, n.title, n.kind, NULL, u.name
            FROM notes n JOIN params p ON n.household_id = p.household_id
            LEFT JOIN users u ON u.id = n.user_id
            WHERE (n.private = 0 OR n.user_id = p.viewer) AND n.created_at >= p.from_utc AND n.created_at < p.to_utc
            SQL,
        'fact' => <<<'SQL'
            SELECT 'fact', f.id, f.created_at, NULL, f.created_at, f.content, f.kind, NULL, u.name
            FROM household_facts f JOIN params p ON f.household_id = p.household_id
            LEFT JOIN users u ON u.id = f.user_id
            WHERE (f.private = 0 OR f.user_id = p.viewer) AND f.created_at >= p.from_utc AND f.created_at < p.to_utc
            SQL,
        'assistant' => <<<'SQL'
            SELECT 'assistant', x.id, x.decided_at, NULL, x.decided_at, x.summary, x.tool, NULL, u.name
            FROM ai_pending_actions x
            JOIN ai_conversations c ON c.id = x.conversation_id
            JOIN params p ON c.household_id = p.household_id AND x.user_id = p.viewer
            JOIN users u ON u.id = x.user_id
            WHERE x.status = 'confirmed' AND x.decided_at >= p.from_utc AND x.decided_at < p.to_utc
            SQL,
    ];

    /** @var list<string> */
    private array $kinds;

    /**
     * @param  list<string>|null  $kinds  only these (keys of BRANCHES); null = all
     * @param  string|null  $search  title or detail contains this
     */
    public function __construct(
        Household $household,
        CarbonImmutable $today,
        /** null: the household view, without anyone's private rows (the digests) */
        private ?User $viewer,
        private CarbonImmutable $from,
        private CarbonImmutable $to,
        ?array $kinds = null,
        private ?string $search = null,
        private int $limit = 50,
    ) {
        parent::__construct($household, $today);
        $this->kinds = $kinds ? array_values(array_intersect(self::kinds(), $kinds)) : self::kinds();
        if ($this->kinds === []) {
            throw new InvalidArgumentException('No such timeline kinds.');
        }
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys(self::BRANCHES);
    }

    public function sql(): string
    {
        return $this->with().<<<'SQL'
            SELECT t.* FROM (
            SQL."\n".$this->union()."\n".<<<'SQL'
            ) t
            WHERE (? = '' OR t.title LIKE ? OR t.detail LIKE ?)
            ORDER BY t.sort_at DESC, t.ref_id DESC
            LIMIT ?
            SQL;
    }

    /** The same rows, counted by kind, for the household digests */
    public function countsSql(): string
    {
        return $this->with()."SELECT t.kind, COUNT(*) AS n, SUM(t.amount) AS amount FROM (\n".$this->union()."\n) t GROUP BY t.kind";
    }

    protected function bindings(): array
    {
        $like = '%'.addcslashes((string) $this->search, '%_\\').'%';

        return [...$this->paramBindings(), (string) $this->search, $like, $like, $this->limit];
    }

    /**
     * @return list<array{kind: string, title: string, detail: string|null, amount: float|null, who: string|null, date: string, time: string|null, id: string}>
     */
    public function get(): array
    {
        $zone = HomeTime::zone();

        $row = function (object $row) use ($zone) {
            /** @var array{kind: string, ref_id: string, at: string|null, on_date: string|null, title: string|null, detail: string|null, amount: string|null, who: string|null} $r */
            $r = (array) $row;
            $at = $r['at'] ? CarbonImmutable::parse($r['at'], 'UTC')->setTimezone($zone) : null;

            return [
                'kind' => $r['kind'],
                'title' => (string) $r['title'],
                'detail' => $r['detail'] === '' ? null : $r['detail'],
                'amount' => $r['amount'] === null ? null : round((float) $r['amount'], 2),
                'who' => $r['who'],
                'date' => $at?->toDateString() ?? (string) $r['on_date'],
                'time' => $at?->format('H:i'),
                'id' => $r['ref_id'],
            ];
        };

        return array_map($row, $this->rows());
    }

    /** @return array<string, array{count: int, amount: float|null}> */
    public function counts(): array
    {
        $out = [];
        foreach (DB::select($this->countsSql(), $this->paramBindings()) as $r) {
            $out[$r->kind] = ['count' => (int) $r->n, 'amount' => $r->amount === null ? null : round((float) $r->amount, 2)];
        }

        return $out;
    }

    private function with(): string
    {
        return <<<'SQL'
            WITH params AS (
                SELECT ? AS household_id, ? AS viewer,
                       CAST(? AS DATE) AS from_date, CAST(? AS DATE) AS to_date,
                       CAST(? AS DATETIME(3)) AS from_utc, CAST(? AS DATETIME(3)) AS to_utc
            )

            SQL;
    }

    private function union(): string
    {
        // A first branch without rows names the columns, whichever branches follow it
        $names = 'SELECT NULL AS kind, NULL AS ref_id, NULL AS at, NULL AS on_date, NULL AS sort_at, NULL AS title, NULL AS detail, NULL AS amount, NULL AS who FROM DUAL WHERE FALSE';

        return implode("\nUNION ALL\n", [$names, ...array_map(fn (string $k) => self::BRANCHES[$k], $this->kinds)]);
    }

    /** @return list<mixed> from and to are days at home; timestamps compare in UTC */
    private function paramBindings(): array
    {
        $zone = HomeTime::zone();

        return [
            $this->household->id,
            $this->viewer->id ?? 0,
            $this->from->toDateString(),
            $this->to->toDateString(),
            CarbonImmutable::parse($this->from->toDateString(), $zone)->utc()->format('Y-m-d H:i:s.v'),
            CarbonImmutable::parse($this->to->toDateString(), $zone)->addDay()->utc()->format('Y-m-d H:i:s.v'),
        ];
    }
}
