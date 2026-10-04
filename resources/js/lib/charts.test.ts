import { describe, expect, test } from 'vitest';
import type { Forecast, WeeklySpendingRow } from '@/types/home';
import { budgetState, categoryColor, OTHER, stackWeeks } from './charts';

const row = (
    week_start: string,
    category: string,
    total: number,
): WeeklySpendingRow => ({
    week_start,
    category,
    total,
    expenses: 1,
    previous_total: null,
    change_pct: null,
});

describe('stackWeeks', () => {
    test('one bar per week, with categories without a colour folded into Other', () => {
        const { bars, keys } = stackWeeks(
            [
                row('2026-09-28', 'groceries', 80),
                row('2026-09-28', 'health', 20),
                row('2026-09-28', 'shopping', 15.5),
                row('2026-10-05', 'groceries', 60.25),
            ],
            ['2026-09-21', '2026-09-28', '2026-10-05'],
        );

        expect(keys).toEqual(['groceries', OTHER]);
        expect(bars).toEqual([
            // A week without spending keeps its place on the axis
            { week_start: '2026-09-21', total: 0 },
            {
                week_start: '2026-09-28',
                total: 115.5,
                groceries: 80,
                [OTHER]: 35.5,
            },
            { week_start: '2026-10-05', total: 60.25, groceries: 60.25 },
        ]);
    });

    test('a category keeps its colour whatever else is shown', () => {
        expect(categoryColor('groceries')).toBe('var(--series-1)');
        expect(categoryColor('fun')).toBe('var(--series-6)');
        expect(categoryColor('health')).toBe('var(--series-other)');
    });
});

describe('budgetState', () => {
    const f = (forecast: number | null, budget: number | null) =>
        ({ forecast, budget }) as Forecast;

    test.each([
        [f(500, null), 'none'],
        [f(null, 1000), 'early'],
        [f(700, 1000), 'under'],
        [f(950, 1000), 'close'],
        [f(1180, 1000), 'over'],
    ])('%o is %s', (forecast, state) => {
        expect(budgetState(forecast)).toBe(state);
    });
});
