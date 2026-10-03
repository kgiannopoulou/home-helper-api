<?php

namespace App\Queries;

use App\Models\Household;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A raw MySQL 8 query for one household, as of one day.
 *
 * "Today" is always a binding, never CURDATE(), so tests can pin it and the
 * answer doesn't depend on the database server's time zone. Each binding is
 * used once, in a `params` CTE, and the rest of the query joins to that.
 */
abstract class InsightQuery
{
    public function __construct(
        protected Household $household,
        protected CarbonImmutable $today,
    ) {}

    abstract public function sql(): string;

    /**
     * @return list<mixed>
     */
    abstract protected function bindings(): array;

    /**
     * @return list<object>
     */
    public function rows(): array
    {
        return DB::select($this->sql(), $this->bindings());
    }

    /**
     * The plan MySQL actually ran, with real row counts and timings.
     */
    public function explainAnalyze(): string
    {
        $row = DB::selectOne('EXPLAIN ANALYZE '.$this->sql(), $this->bindings());

        return (string) current((array) $row);
    }
}
