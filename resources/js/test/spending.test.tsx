import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { page } from '@/test/inertia';
import type { Forecast } from '@/types/home';
import Spending from '@/pages/spending';

vi.mock(
    '@inertiajs/react',
    async () => (await import('@/test/inertia')).inertiaMock,
);

const forecast = (over: Partial<Forecast>): Forecast => ({
    forecast: 1395,
    budget: 1000,
    over_budget: 395,
    spent: 900,
    bills: 620,
    projected_from_pace: 1395,
    day: 20,
    days_in_month: 31,
    history: { months: 0, usual_by_this_day: null, average_month: null },
    ...over,
});

const props = (f: Forecast) => ({
    weeks: 4,
    weekStarts: ['2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05'],
    spending: [
        {
            week_start: '2026-09-28',
            category: 'groceries',
            total: 80,
            expenses: 2,
            previous_total: null,
            change_pct: null,
        },
        {
            week_start: '2026-09-28',
            category: 'health',
            total: 20,
            expenses: 1,
            previous_total: null,
            change_pct: null,
        },
        {
            week_start: '2026-10-05',
            category: 'groceries',
            total: 60,
            expenses: 1,
            previous_total: 80,
            change_pct: -25,
        },
    ],
    forecast: f,
});

describe('spending page', () => {
    beforeEach(() => {
        page.props = {
            household: {
                id: 'h',
                name: 'Patission 12',
                currency: 'EUR',
                role: 'owner',
            },
        };
    });

    test('warns when the month is heading over budget', () => {
        render(<Spending {...props(forecast({}))} />);

        expect(screen.getByText('€1,395 at this pace')).toBeInTheDocument();
        expect(
            screen.getByText(/€395 over your €1,000 budget/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('meter', { name: 'Forecast against budget' }),
        ).toHaveAttribute('aria-valuenow', '1395');
    });

    test('says so when it is too early for a forecast, or there is no budget', () => {
        const { rerender } = render(
            <Spending
                {...props(
                    forecast({
                        forecast: null,
                        over_budget: null,
                        spent: 40,
                        day: 3,
                    }),
                )}
            />,
        );
        expect(
            screen.getByText('Too early in the month for a forecast.'),
        ).toBeInTheDocument();

        rerender(
            <Spending
                {...props(forecast({ budget: null, over_budget: null }))}
            />,
        );
        expect(screen.getByText(/No monthly budget set/)).toBeInTheDocument();
        expect(screen.queryByRole('meter')).not.toBeInTheDocument();
    });

    test('the chart has the same numbers as a table, every week included', () => {
        render(<Spending {...props(forecast({}))} />);

        const table = screen.getByRole('table', { name: 'Spending per week' });
        const rows = table.querySelectorAll('tbody tr');
        expect(rows).toHaveLength(4);
        expect(rows[0]).toHaveTextContent('14 Sep€0€0€0');
        expect(rows[2]).toHaveTextContent('28 Sep€80€20€100');
        expect(
            screen.getAllByRole('columnheader').map((h) => h.textContent),
        ).toEqual(['Week of', 'Groceries', 'Other', 'Total']);
    });

    test('the range links keep the chosen one marked', () => {
        render(<Spending {...props(forecast({}))} />);

        expect(screen.getByRole('link', { name: '4 weeks' })).toHaveAttribute(
            'aria-current',
            'true',
        );
        expect(screen.getByRole('link', { name: '12 weeks' })).toHaveAttribute(
            'href',
            '/spending?weeks=12',
        );
    });
});
