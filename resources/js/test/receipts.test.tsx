import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { ReceiptCard } from '@/components/kitchen/receipt-card';
import { lineAmount } from '@/lib/kitchen';
import { inertiaMock } from '@/test/inertia';
import type { ReceiptDraft, ReceiptLine } from '@/types/kitchen';

vi.mock(
    '@inertiajs/react',
    async () => (await import('@/test/inertia')).inertiaMock,
);

const line = (over: Partial<ReceiptLine>): ReceiptLine => ({
    index: 0,
    name: 'Γαλα φρεσκο 1l',
    raw: 'ΓΑΛΑ ΦΡΕΣΚΟ 1L',
    price: 1.89,
    discount: 0,
    quantity: null,
    amount: 1000,
    unit: 'ml',
    ingredient: 'milk',
    food: true,
    include: true,
    item_id: 'milk-id',
    item_name: 'Milk',
    ...over,
});

const draft: ReceiptDraft = {
    id: 'r1',
    status: 'draft',
    source: 'photo',
    store: 'Sklavenitis',
    purchased_on: '2026-11-13',
    total: 10.59,
    adds_up: false,
    lines: [
        line({}),
        line({
            index: 1,
            name: 'Φετα ποπ 400γρ',
            raw: 'ΦΕΤΑ ΠΟΠ 400ΓΡ',
            price: 5.0,
            discount: 0.6,
            amount: 400,
            unit: 'g',
            ingredient: 'feta',
            item_id: null,
            item_name: null,
        }),
        line({
            index: 2,
            name: 'Χαρτι κουζινασ',
            raw: 'ΧΑΡΤΙ ΚΟΥΖΙΝΑΣ',
            price: 3.1,
            amount: null,
            unit: null,
            ingredient: 'χαρτι κουζινασ',
            food: false,
            include: false,
            item_id: null,
            item_name: null,
        }),
    ],
    shopping_trip_id: null,
    created_at: '2026-11-13T16:00:00Z',
};

describe('receipts', () => {
    beforeEach(() => vi.mocked(inertiaMock.router.post).mockClear());

    test('amount hints', () => {
        expect(lineAmount({ amount: 1000, unit: 'g' })).toBe('1 kg');
        expect(lineAmount({ amount: 850, unit: 'g' })).toBe('850 g');
        expect(lineAmount({ amount: 6, unit: 'pcs' })).toBe('6');
        expect(lineAmount({ amount: null, unit: null })).toBe('');
    });

    test('without a draft: a photo or the text', () => {
        render(<ReceiptCard receipt={null} photos />);
        expect(screen.getByLabelText('Photo')).toHaveAttribute('type', 'file');
        expect(screen.getByLabelText('Or the text')).toBeInTheDocument();
    });

    test('without OCR: only the text', () => {
        render(<ReceiptCard receipt={null} photos={false} />);
        expect(screen.queryByLabelText('Photo')).not.toBeInTheDocument();
        expect(screen.getByLabelText('The text')).toBeInTheDocument();
    });

    test('the draft: matched items, a warning, the discount, non-food unticked', () => {
        render(<ReceiptCard receipt={draft} photos />);
        expect(screen.getByLabelText('Name for ΓΑΛΑ ΦΡΕΣΚΟ 1L')).toHaveValue(
            'Milk',
        );
        expect(screen.getByText(/adds to what you have/)).toBeInTheDocument();
        expect(screen.getByText(/don't add up/)).toBeInTheDocument();
        expect(screen.getByText('−€0.60')).toBeInTheDocument();
        expect(
            screen.getByLabelText('Add ΧΑΡΤΙ ΚΟΥΖΙΝΑΣ to the kitchen'),
        ).not.toBeChecked();
        expect(
            screen.getByRole('button', { name: /Import 2 items/ }),
        ).toBeInTheDocument();
    });

    test('imports what was corrected', () => {
        render(<ReceiptCard receipt={draft} photos />);
        fireEvent.click(
            screen.getByLabelText('Add ΓΑΛΑ ΦΡΕΣΚΟ 1L to the kitchen'),
        );
        fireEvent.change(screen.getByLabelText('Name for ΦΕΤΑ ΠΟΠ 400ΓΡ'), {
            target: { value: 'Feta' },
        });
        fireEvent.change(screen.getByLabelText('How much ΦΕΤΑ ΠΟΠ 400ΓΡ'), {
            target: { value: '450 g' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Import 1 item/ }));

        expect(inertiaMock.router.post).toHaveBeenCalledWith(
            '/kitchen/receipts/r1/import',
            {
                store: 'Sklavenitis',
                purchased_on: '2026-11-13',
                lines: {
                    0: { include: false, amount_text: '' },
                    1: {
                        include: true,
                        item_id: null,
                        name: 'Feta',
                        amount_text: '450 g',
                    },
                    2: {
                        include: false,
                        item_id: null,
                        name: 'Χαρτι κουζινασ',
                        amount_text: '',
                    },
                },
            },
            expect.anything(),
        );
    });
});
