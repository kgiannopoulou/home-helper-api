import type { Forecast, WeeklySpendingRow } from '@/types/home';

/**
 * Each spending category keeps the same colour on every chart and every filter:
 * colour follows the category, never its rank. Six get a slot; the rest share a
 * grey "Other" (a seventh hue would be too close to one of the six to tell apart).
 */
export const CATEGORY_SLOTS: Record<string, string> = {
    groceries: 'var(--series-1)',
    bills: 'var(--series-2)',
    eating_out: 'var(--series-3)',
    household: 'var(--series-4)',
    transport: 'var(--series-5)',
    fun: 'var(--series-6)',
};

export const OTHER = 'other_categories';
export const OTHER_COLOR = 'var(--series-other)';

export function categoryColor(category: string): string {
    return CATEGORY_SLOTS[category] ?? OTHER_COLOR;
}

/** One bar per week: a key per category with a slot, the rest summed as OTHER. */
export type WeekBar = {
    week_start: string;
    total: number;
    [category: string]: number | string;
};

/**
 * The query's rows (one per week and category) as one row per week, for a
 * stacked bar chart. Weeks with no spending are kept as zeros, so the time axis
 * has no gaps. Also returns which stacks appear, in a fixed order.
 */
export function stackWeeks(
    rows: WeeklySpendingRow[],
    weekStarts: string[],
): { bars: WeekBar[]; keys: string[] } {
    const slotted = Object.keys(CATEGORY_SLOTS);
    const bars = weekStarts.map((week_start) => {
        const bar: WeekBar = { week_start, total: 0 };

        for (const r of rows.filter((row) => row.week_start === week_start)) {
            const key = slotted.includes(r.category) ? r.category : OTHER;
            bar[key] = round2(Number(bar[key] ?? 0) + r.total);
            bar.total = round2(bar.total + r.total);
        }

        return bar;
    });
    const present = new Set(
        rows.map((r) => (slotted.includes(r.category) ? r.category : OTHER)),
    );
    const keys = [...slotted, OTHER].filter((k) => present.has(k));

    return { bars, keys };
}

export type BudgetState = 'none' | 'early' | 'under' | 'close' | 'over';

/**
 * How the month is going against the budget: no budget, too early to tell
 * (no forecast yet), under, close (within 10%), or over.
 */
export function budgetState(f: Forecast): BudgetState {
    if (f.budget === null) {
        return 'none';
    }

    if (f.forecast === null) {
        return 'early';
    }

    if (f.forecast > f.budget) {
        return 'over';
    }

    return f.forecast >= f.budget * 0.9 ? 'close' : 'under';
}

function round2(n: number): number {
    return Math.round(n * 100) / 100;
}
