import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { inertiaMock, posts } from '@/test/inertia';
import type { PlanItem, WeekPlan } from '@/types/home';
import Planner from '@/pages/planner';

vi.mock(
    '@inertiajs/react',
    async () => (await import('@/test/inertia')).inertiaMock,
);

const item = (over: Partial<PlanItem>): PlanItem => ({
    key: 'k',
    kind: 'chore',
    title: 'Kitchen: Mop',
    detail: 'every 7 days',
    minutes: 15,
    wanted: '2026-10-14',
    date: '2026-10-14',
    start: '17:30',
    end: '17:45',
    moved: false,
    applied: false,
    ...over,
});

const days = ['12', '13', '14', '15', '16', '17', '18'].map((d) => ({
    date: `2026-10-${d}`,
    free: d === '17' ? [] : [{ from: '17:30', to: '21:30' }],
    busy:
        d === '17'
            ? [{ title: 'Nafplio', from: '00:00', to: '24:00', all_day: true }]
            : [],
}));

const plan: WeekPlan = {
    week_start: '2026-10-12',
    days,
    items: [
        item({ key: 'chore:mop:2026-10-14' }),
        item({
            key: 'meal:2026-10-15',
            kind: 'meal',
            title: 'Cook with Feta',
            date: '2026-10-15',
            wanted: '2026-10-15',
            start: '18:00',
            minutes: 45,
        }),
        item({
            key: 'todo:shelf',
            kind: 'errand',
            title: 'Fix the shelf',
            date: '2026-10-12',
            wanted: null,
            start: '20:10',
            minutes: 60,
        }),
        item({
            key: 'chore:oven:2026-10-12',
            title: 'Kitchen: Clean the oven',
            date: '2026-10-12',
            start: '19:00',
            applied: true,
        }),
    ],
    unplaced: [
        {
            key: 'chore:paint:2026-10-12',
            kind: 'chore',
            title: 'Kitchen: Paint the hall',
            detail: null,
            minutes: 600,
            wanted: '2026-10-12',
        },
    ],
};

describe('week planner', () => {
    beforeEach(() => {
        posts.length = 0;
        inertiaMock.router.post.mockClear();
    });

    test('shows each day with its items, the calendar and what did not fit', () => {
        render(<Planner plan={plan} />);

        const wednesday = screen
            .getByText('Wed 14 Oct')
            .closest('[data-slot="card"]') as HTMLElement;
        expect(within(wednesday).getByText('Kitchen: Mop')).toBeInTheDocument();
        expect(within(wednesday).getByText('17:30')).toBeInTheDocument();
        expect(screen.getByText(/All day · Nafplio/)).toBeInTheDocument();
        expect(screen.getByText(/Paint the hall/)).toBeInTheDocument();
        expect(screen.getByText(/in the calendar/)).toBeInTheDocument();
    });

    test('everything not yet in the calendar starts ticked; Apply sends the ticked keys', () => {
        render(<Planner plan={plan} />);

        expect(screen.getByRole('button', { name: 'Apply 3' })).toBeEnabled();
        expect(
            screen.getByRole('checkbox', { name: /Clean the oven/ }),
        ).toBeDisabled();

        fireEvent.click(
            screen.getByRole('checkbox', { name: /Cook with Feta/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Apply 2' }));

        expect(posts).toEqual([
            {
                url: '/planner/apply',
                data: {
                    week_start: '2026-10-12',
                    keys: ['chore:mop:2026-10-14', 'todo:shelf'],
                },
            },
        ]);
    });

    test('hiding a kind keeps it ticked, and nothing ticked means nothing to apply', () => {
        render(<Planner plan={plan} />);

        fireEvent.click(screen.getByRole('button', { name: /Meals/ }));
        expect(screen.queryByText('Cook with Feta')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Apply 3' }),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Untick all' }));
        expect(screen.getByRole('button', { name: 'Apply 0' })).toBeDisabled();
    });
});
