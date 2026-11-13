import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { ExpiringCard, WasteCard } from '@/components/kitchen/expiry-cards';
import { expiryText } from '@/lib/kitchen';
import { inertiaMock } from '@/test/inertia';
import { kitchenItem } from '@/test/kitchen-factories';

vi.mock(
    '@inertiajs/react',
    async () => (await import('@/test/inertia')).inertiaMock,
);

describe('expiry', () => {
    beforeEach(() => vi.mocked(inertiaMock.router.post).mockClear());

    test('says when, and whether it is an estimate', () => {
        const at = (days: number, estimated = false) =>
            expiryText(
                kitchenItem({
                    expiry: { state: 'soon', days },
                    expiry_estimated: estimated,
                }),
            );
        expect(at(0)).toBe('use today');
        expect(at(1, true)).toBe('use by tomorrow (about)');
        expect(at(3)).toBe('use within 3 days');
        expect(at(-1)).toBe('went off yesterday');
        expect(at(-4)).toBe('went off 4 days ago');
    });

    test('use soon: used up, opened, the date on the pack', () => {
        render(
            <ExpiringCard
                items={[
                    kitchenItem({
                        expires_on: '2026-11-14',
                        expiry: { state: 'soon', days: 1 },
                    }),
                ]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Used up Milk' }));
        fireEvent.click(screen.getByRole('button', { name: 'Opened Milk' }));
        const date = screen.getByLabelText('Date on the pack of Milk');
        fireEvent.change(date, { target: { value: '2026-11-18' } });
        fireEvent.blur(date);

        expect(
            vi
                .mocked(inertiaMock.router.post)
                .mock.calls.map(([url, data]) => [url, data]),
        ).toEqual([
            ['/kitchen/items/01jitem0000000000000000000/use', { kind: 'used' }],
            ['/kitchen/items/01jitem0000000000000000000/opened', {}],
            [
                '/kitchen/items/01jitem0000000000000000000/expiry',
                { expires_on: '2026-11-18' },
            ],
        ]);
    });

    test('gone off: thrown away counts as expired, or still good', () => {
        render(
            <ExpiringCard
                items={[
                    kitchenItem({ expiry: { state: 'expired', days: -2 } }),
                ]}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'Opened Milk' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Threw away Milk' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Milk is still good' }),
        );

        expect(
            vi
                .mocked(inertiaMock.router.post)
                .mock.calls.map(([url, data]) => [url, data]),
        ).toEqual([
            [
                '/kitchen/items/01jitem0000000000000000000/use',
                { kind: 'expired' },
            ],
            ['/kitchen/items/01jitem0000000000000000000/still-good', {}],
        ]);
    });

    test('waste: items and what they cost', () => {
        render(
            <WasteCard
                waste={{
                    days: 30,
                    value: 3,
                    priced: 1,
                    items: [
                        {
                            id: 'f',
                            name: 'Feta',
                            ingredient: 'feta',
                            amount: 200,
                            unit: 'g',
                            times: 2,
                            value: 3,
                        },
                        {
                            id: 'l',
                            name: 'Lettuce',
                            ingredient: 'lettuce',
                            amount: 1,
                            unit: 'pcs',
                            times: 1,
                            value: null,
                        },
                    ],
                }}
            />,
        );
        expect(screen.getByText(/About €3.00/)).toBeInTheDocument();
        expect(screen.getByText('—')).toBeInTheDocument();
    });

    test('nothing thrown away', () => {
        render(
            <WasteCard waste={{ days: 30, value: 0, priced: 0, items: [] }} />,
        );
        expect(screen.getByText(/Nothing thrown away/)).toBeInTheDocument();
    });
});
