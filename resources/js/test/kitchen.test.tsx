import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { byLocation, fullness } from '@/lib/kitchen';
import Kitchen from '@/pages/kitchen';
import { inertiaMock } from '@/test/inertia';
import { kitchenItem } from '@/test/kitchen-factories';
import type { Movement } from '@/types/kitchen';

vi.mock(
    '@inertiajs/react',
    async () => (await import('@/test/inertia')).inertiaMock,
);

const movement: Movement = {
    id: '01jmove0000000000000000000',
    item_id: '01jitem0000000000000000000',
    item: 'Milk',
    kind: 'used',
    amount: -250,
    unit: 'ml',
    label: '−250 ml',
    by: 'Eleni',
    note: null,
    occurred_at: '2026-11-13T10:00:00Z',
};

describe('kitchen helpers', () => {
    test('groups by storage place, fridge first, empty places left out', () => {
        const groups = byLocation([
            kitchenItem({ id: 'a', location: 'pantry' }),
            kitchenItem({ id: 'b', location: 'fridge' }),
            kitchenItem({ id: 'c', location: 'freezer' }),
        ]);
        expect(groups.map((g) => g.location)).toEqual([
            'fridge',
            'freezer',
            'pantry',
        ]);
    });

    test('fullness from the amount, else from the level', () => {
        expect(fullness(kitchenItem({ amount: 250 }))).toBe(0.25);
        expect(fullness(kitchenItem({ amount: null, level: 'half' }))).toBe(
            0.5,
        );
    });
});

describe('Kitchen page', () => {
    beforeEach(() => vi.mocked(inertiaMock.router.post).mockClear());

    test('shows items with amounts and the history', () => {
        render(
            <Kitchen
                items={[
                    kitchenItem(),
                    kitchenItem({
                        id: 'f',
                        name: 'Γιαούρτι',
                        ingredient: 'yogurt',
                        amount: 200,
                        full_amount: 1000,
                        level: 'low',
                        amount_text: '200 g',
                        unit: 'g',
                    }),
                ]}
                movements={[movement]}
                locations={['fridge', 'freezer', 'pantry', 'cleaning']}
                receipt={null}
                photos
                expiring={[]}
                waste={{ days: 30, items: [], value: 0, priced: 0 }}
                recipes={[]}
            />,
        );

        expect(screen.getByText('2 things')).toBeInTheDocument();
        expect(screen.getByText('· yogurt')).toBeInTheDocument();
        expect(
            screen.getByRole('meter', { name: 'Γιαούρτι left' }),
        ).toHaveAttribute('aria-valuenow', '20');
        expect(screen.getByText('−250 ml')).toBeInTheDocument();
        // Cleaning isn't a kitchen place
        expect(
            screen.queryByRole('option', { name: 'Cleaning' }),
        ).not.toBeInTheDocument();
    });

    test('sends what was used, wasted or added', () => {
        render(
            <Kitchen
                items={[kitchenItem()]}
                movements={[]}
                locations={['fridge']}
                receipt={null}
                photos={false}
                expiring={[]}
                waste={{ days: 30, items: [], value: 0, priced: 0 }}
                recipes={[]}
            />,
        );

        expect(screen.getByRole('button', { name: 'Add Milk' })).toBeDisabled();
        fireEvent.change(screen.getByLabelText('How much Milk'), {
            target: { value: '250 ml' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Used Milk' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Threw away Milk' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Add Milk' }));

        const calls = vi
            .mocked(inertiaMock.router.post)
            .mock.calls.map(([url, data]) => [url, data]);
        expect(calls).toEqual([
            [
                '/kitchen/items/01jitem0000000000000000000/use',
                { kind: 'used', amount_text: '250 ml' },
            ],
            [
                '/kitchen/items/01jitem0000000000000000000/use',
                { kind: 'wasted', amount_text: '250 ml' },
            ],
            [
                '/kitchen/items/01jitem0000000000000000000/add',
                { amount_text: '250 ml' },
            ],
        ]);
    });
});
