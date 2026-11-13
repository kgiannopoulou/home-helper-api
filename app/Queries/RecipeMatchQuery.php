<?php

namespace App\Queries;

use App\Models\Household;
use Carbon\CarbonImmutable;

/**
 * What to cook with what's in the kitchen (docs/kitchen.md#recipes).
 *
 * `stock` is the kitchen per ingredient key: everything with something left,
 * its amount where measured, the soonest use-by date. Each recipe's
 * ingredients are joined to it; optional ones and staples (salt, oil) never
 * count against a recipe. Per recipe:
 *
 *   missing   ingredients not in the kitchen at all
 *   short     there, but less than the recipe needs (when both are measured
 *             in units that compare: g with g or ml, pieces with pieces)
 *   uses_soon ingredients going off within the next days: cook these first
 *
 * Ranked: fewest missing, then most of what goes off soon, then the
 * soonest date, then the quickest. Recipes with nothing in the kitchen are
 * left out. Optional filter: one tag (vegetarian, quick, breakfast...).
 */
class RecipeMatchQuery extends InsightQuery
{
    public function __construct(
        Household $household,
        CarbonImmutable $today,
        private ?string $tag = null,
        private int $limit = 12,
        private int $soonDays = 3,
    ) {
        parent::__construct($household, $today);
    }

    public function sql(): string
    {
        return <<<'SQL'
            WITH params AS (
                SELECT ? AS household_id, CAST(? AS DATE) AS soon_until, ? AS tag
            ),
            stock AS (
                SELECT i.ingredient,
                       SUM(i.amount) AS amount,
                       MIN(i.unit) AS unit,
                       COUNT(DISTINCT i.unit) AS units,
                       SUM(i.amount IS NULL) AS unmeasured,
                       MIN(i.expires_on) AS expires_on
                FROM inventory_items i
                JOIN params p ON i.household_id = p.household_id
                WHERE i.deleted_at IS NULL AND i.ingredient IS NOT NULL
                  AND (i.amount > 0 OR (i.amount IS NULL AND i.level <> 'empty'))
                GROUP BY i.ingredient
            ),
            needs AS (
                SELECT r.id AS recipe_id, ri.ingredient,
                       (NOT ri.optional AND NOT ri.staple) AS counts,
                       s.ingredient IS NOT NULL AS have,
                       s.ingredient IS NOT NULL AND ri.amount IS NOT NULL AND s.unmeasured = 0 AND s.units = 1
                           AND (s.unit = ri.unit OR (s.unit IN ('g', 'ml') AND ri.unit IN ('g', 'ml')))
                           AND s.amount < ri.amount AS short,
                       s.expires_on <= p.soon_until AS soon,
                       s.expires_on
                FROM recipes r
                JOIN params p ON r.household_id IS NULL OR r.household_id = p.household_id
                JOIN recipe_ingredients ri ON ri.recipe_id = r.id
                LEFT JOIN stock s ON s.ingredient = ri.ingredient
                WHERE p.tag IS NULL OR JSON_CONTAINS(r.tags, JSON_QUOTE(p.tag))
            ),
            scored AS (
                SELECT recipe_id,
                       SUM(counts) AS required,
                       SUM(counts AND have) AS have,
                       SUM(counts AND short) AS short,
                       SUM(have AND soon) AS uses_soon_count,
                       GROUP_CONCAT(CASE WHEN counts AND NOT have THEN ingredient END ORDER BY ingredient SEPARATOR '|') AS missing,
                       GROUP_CONCAT(CASE WHEN have AND soon THEN ingredient END ORDER BY expires_on, ingredient SEPARATOR '|') AS uses_soon,
                       MIN(CASE WHEN have AND soon THEN expires_on END) AS soonest
                FROM needs
                GROUP BY recipe_id
            )
            SELECT r.id, r.slug, r.name, r.servings, r.minutes, r.tags, r.household_id,
                   sc.required, sc.have, sc.short, sc.missing, sc.uses_soon, sc.soonest
            FROM scored sc
            JOIN recipes r ON r.id = sc.recipe_id
            WHERE sc.have > 0
            ORDER BY sc.required - sc.have, sc.uses_soon_count DESC, sc.soonest IS NULL, sc.soonest,
                     sc.short, r.minutes, r.name
            LIMIT ?
            SQL;
    }

    protected function bindings(): array
    {
        return [$this->household->id, $this->today->addDays($this->soonDays)->toDateString(), $this->tag, $this->limit];
    }

    /**
     * @return list<array{id: string, slug: string|null, name: string, servings: int, minutes: int|null, tags: list<string>, own: bool, required: int, have: int, short: int, missing: list<string>, uses_soon: list<string>, soonest: string|null, ready: bool}>
     */
    public function get(): array
    {
        return array_map(function (object $row) {
            /** @var array{id: string, slug: string|null, name: string, servings: int|string, minutes: int|string|null, tags: string, household_id: string|null, required: int|string, have: int|string, short: int|string|null, missing: string|null, uses_soon: string|null, soonest: string|null} $r */
            $r = (array) $row;
            $missing = $r['missing'] ? explode('|', $r['missing']) : [];

            return [
                'id' => $r['id'],
                'slug' => $r['slug'],
                'name' => $r['name'],
                'servings' => (int) $r['servings'],
                'minutes' => $r['minutes'] === null ? null : (int) $r['minutes'],
                'tags' => array_values(array_map('strval', (array) json_decode($r['tags'], true))),
                'own' => $r['household_id'] !== null,
                'required' => (int) $r['required'],
                'have' => (int) $r['have'],
                'short' => (int) $r['short'],
                'missing' => $missing,
                'uses_soon' => $r['uses_soon'] ? explode('|', $r['uses_soon']) : [],
                'soonest' => $r['soonest'],
                // Everything there, and enough of what's measured
                'ready' => $missing === [] && (int) $r['short'] === 0,
            ];
        }, $this->rows());
    }
}
