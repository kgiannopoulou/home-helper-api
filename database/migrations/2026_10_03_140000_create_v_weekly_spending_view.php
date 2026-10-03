<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Spending per household, Monday week and category: the most used grouping,
     * shared by the weekly-spending insight and (later) the dashboard.
     *
     * YEARWEEK(date, 1) and the Monday of the week make the same groups; the
     * Monday is kept because it is a real date that is easy to filter and show.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_weekly_spending AS
            SELECT household_id,
                   YEARWEEK(date, 1) AS year_week,
                   DATE_SUB(date, INTERVAL WEEKDAY(date) DAY) AS week_start,
                   category,
                   SUM(amount) AS total,
                   COUNT(*) AS expenses
            FROM expenses
            WHERE deleted_at IS NULL
            GROUP BY household_id, year_week, week_start, category
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_weekly_spending');
    }
};
