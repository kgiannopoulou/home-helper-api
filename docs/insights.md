# Insights: trends and predictions in SQL

The phone works these out in TypeScript (`predictions.ts`, `history.ts`, `inventory.ts`). Here they are MySQL 8 queries, one class each in `app/Queries`, with the same rules and the same test cases as the app's Jest tests.

| `GET /api/households/{household}/insights/…` | What it answers | SQL |
|---|---|---|
| `weekly-spending?weeks=8` | Spending per Monday week and category, against the week before | the `v_weekly_spending` view (`GROUP BY YEARWEEK(date, 1)`), a CTE, `LAG()` over each category |
| `run-out` | When each kitchen item runs out, from how often it's bought | `SELECT DISTINCT` days, `LAG()` + `DATEDIFF()`, `AVG()` of the gaps, `HAVING COUNT(gap) >= 2` |
| `slipping-chores` | Chores whose last 3 gaps all ran 30% late (or early), and a better frequency | `LAG()` and `ROW_NUMBER()` over completion days, a `UNION ALL` row for "overdue right now", the median of 3 as `SUM − MIN − MAX`, a frequencies CTE |
| `shopping-day` | Your usual shopping weekday and when it next comes round | `UNION` of trips and grocery spends, `DAYOFWEEK()`, `COUNT(*)` with `SUM(COUNT(*)) OVER ()` for the share, `ROW_NUMBER()` to rank, last 12 weeks |
| `budget-forecast` | Where this month ends | CTEs for this month, the last 3 months by the same day and in all, the budget; bills aren't extrapolated |

Example: run-out, the query in `app/Queries/RunOutQuery.php`:

```sql
WITH params AS (SELECT ? AS household_id, CAST(? AS DATE) AS today),
days AS (                          -- two purchases on one day count once
    SELECT DISTINCT pu.inventory_item_id, pu.bought_on
    FROM purchases pu JOIN params p ON pu.household_id = p.household_id
    WHERE pu.deleted_at IS NULL
),
gaps AS (
    SELECT inventory_item_id, bought_on,
           DATEDIFF(bought_on, LAG(bought_on) OVER (PARTITION BY inventory_item_id ORDER BY bought_on)) AS gap_days
    FROM days
),
rhythm AS (
    SELECT inventory_item_id, MAX(bought_on) AS last_bought, COUNT(*) AS times_bought,
           GREATEST(1, ROUND(AVG(gap_days))) AS every_days
    FROM gaps GROUP BY inventory_item_id
    HAVING COUNT(gap_days) >= 2    -- at least 3 purchase days, like the app
)
SELECT i.id, i.name, i.level, r.last_bought, r.times_bought, r.every_days,
       r.last_bought + INTERVAL r.every_days DAY AS runs_out_on,
       DATEDIFF(r.last_bought + INTERVAL r.every_days DAY, p.today) AS days_left
FROM rhythm r
JOIN inventory_items i ON i.id = r.inventory_item_id AND i.deleted_at IS NULL
CROSS JOIN params p
ORDER BY runs_out_on, i.name;
```

"Today" is always a binding, never `CURDATE()`, so tests can pin the day and the server's time zone doesn't matter.

## Three queries and their plans

1. **Weekly spending** (`WeeklySpendingQuery`): the view, read once, with `LAG()` for the week before. It went from 521 ms to 0.37 ms (below).

    ```sql
    WITH weeks AS (
        SELECT v.week_start, v.category, v.total, v.expenses
        FROM v_weekly_spending v
        WHERE v.household_id = ?                     -- a constant: pushed down into the grouped view
          AND v.week_start BETWEEN CAST(? AS DATE) - INTERVAL 7 DAY AND CAST(? AS DATE)
    ),
    compared AS (
        SELECT weeks.*,
               CASE WHEN LAG(week_start) OVER w = week_start - INTERVAL 7 DAY THEN LAG(total) OVER w END AS previous_total
        FROM weeks
        WINDOW w AS (PARTITION BY category ORDER BY week_start)
    )
    SELECT week_start, category, total, expenses, previous_total,
           ROUND((total - previous_total) / previous_total * 100) AS change_pct
    FROM compared WHERE week_start >= CAST(? AS DATE)
    ORDER BY week_start, total DESC, category;
    ```

2. **Run-out** (`RunOutQuery`, above): `LAG()` over purchase days. It went from 1.2 ms to 0.72 ms with a covering index.
3. **Budget forecast** (`BudgetForecastQuery`): one-row CTEs that MySQL works out while planning. It went from 2.7 ms to 1.9 ms.

The plans for all three follow, from [`docs/explain/`](explain).

## EXPLAIN ANALYZE: before and after

Measured on benchmark data: the demo household copied 500 times (`php artisan db:seed --class=BenchmarkSeeder`, done in SQL with a recursive CTE and `INSERT … SELECT`). That makes 93,000 expenses, 83,500 purchases and 189,000 chore completions. `php artisan insights:explain before|after` saves every plan to [`docs/explain/`](explain).

| Query | Before | After | What changed |
|---|---:|---:|---|
| weekly-spending | **521 ms** | **0.37 ms** | query rewrite + covering index |
| run-out | 1.2 ms | 0.72 ms | covering index |
| budget-forecast | 2.7 ms | 1.9 ms | covering index (timed: see below) |
| slipping-chores | 3.8 ms | 3.8–5 ms | already used `(chore_id, done_at)` |
| shopping-day | 0.14 ms | 0.16 ms | already used `(household_id, category, date)` and `(household_id, date)` |

**Weekly spending: 1,400× faster.** Before, MySQL grouped *every household's* expenses into a 64,629-row temporary table, twice:

```
-> Nested loop left join   (521 ms, 39 rows)
    -> Index lookup on v using <auto_key0> (household_id='01m4…')   (255 ms, 129 rows)
        -> Materialize   (255 ms, 64629 rows)
            -> Aggregate using temporary table   (147 ms, 64629 rows)
                -> Table scan on expenses   (18.3 ms, 93186 rows)
    -> Index lookup on v using <auto_key0> (household_id=p.household_id, week_start=…)
        -> Materialize   (266 ms, 64629 rows)          ← the same again, for "the week before"
            -> Table scan on expenses   (18.2 ms, 93186 rows)
```

There were two causes:

1. **The household id came from a join to a `params` CTE.** MySQL pushes a condition down into a grouped view only when it's a constant. The rewrite binds `v.household_id = ?` directly ([`weekly-spending.rewrite.txt`](explain/weekly-spending.rewrite.txt): 0.52 ms).
2. **The view was read twice.** "The week before" was a self-join, and the second read didn't get the pushdown. `LAG() OVER (PARTITION BY category ORDER BY week_start)` reads it once. It only counts when the previous row really is 7 days earlier.

Then a covering index, `expenses (household_id, date, category, amount, source, deleted_at)`, lets MySQL answer from the index alone. It replaces `(household_id, date)`, which it starts with:

```
-> Sort: compared.week_start, compared.total DESC, compared.category   (0.366 ms, 39 rows)
    -> Window aggregate with buffering: lag(weeks.week_start) OVER w, lag(weeks.total) OVER w   (0.328 ms, 44 rows)
        -> Aggregate using temporary table   (0.172 ms, 44 rows)
            -> Covering index lookup on expenses using expenses_household_date_covering (household_id='01m4…')
```

**Run-out:** before, it went through the sync index `(household_id, updated_at)` and then to each row to check `deleted_at`. `purchases (household_id, inventory_item_id, bought_on, deleted_at)` makes that a covering index lookup:

```
before  -> Index lookup on pu using purchases_household_id_updated_at_index (household_id='01m4…')
after   -> Covering index lookup on pu using purchases_household_item_day_covering (household_id='01m4…')
```

**Budget forecast:** every CTE returns one row, so MySQL works the whole query out while planning. `EXPLAIN ANALYZE` only shows `Rows fetched before execution`. The classic `EXPLAIN` shows the expense reads changing from `ref expenses_household_id_updated_at_index` to `range expenses_household_date_covering … Using index`. Averaged over 20 runs, it went from 2.7 to 1.9 ms.

