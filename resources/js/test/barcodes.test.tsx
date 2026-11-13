import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';
import {
    BarcodeScanner,
    ScanResultView,
} from '@/components/kitchen/barcode-scanner';
import { isBarcode } from '@/lib/barcodes';
import { inertiaMock } from '@/test/inertia';
import { kitchenItem } from '@/test/kitchen-factories';
import type { ScanResult } from '@/types/kitchen';

vi.mock(
    '@inertiajs/react',
    async () => (await import('@/test/inertia')).inertiaMock,
);

const FAGE = '5201054017128';

const product: NonNullable<ScanResult['product']> = {
    gtin: FAGE,
    name: 'Total 2% στραγγιστό γιαούρτι',
    brand: 'Fage',
    quantity: '1 kg',
    amount: 1000,
    unit: 'g',
    ingredient: 'yogurt',
    category: 'food',
    location: 'fridge',
    image_url: null,
    source: 'openfoodfacts',
};

const result = (over: Partial<ScanResult> = {}): ScanResult => ({
    gtin: FAGE,
    item: null,
    suggestion: null,
    product,
    lookup_error: null,
    ...over,
});

describe('isBarcode', () => {
    test.each([
        [FAGE, true],
        ['96385074', true],
        ['036000291452', true],
        ['5201054017129', false],
        ['12345', false],
    ])('%s → %s', (code, ok) => expect(isBarcode(code)).toBe(ok));
});

describe('the scanner', () => {
    beforeEach(() => vi.mocked(inertiaMock.router.post).mockClear());

    test('refuses a misread before asking the server', () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch');
        render(<BarcodeScanner locations={['fridge']} />);
        fireEvent.change(screen.getByLabelText('Barcode'), {
            target: { value: '5201054017129' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Look up' }));

        expect(screen.getByRole('alert')).toHaveTextContent('check the digits');
        expect(fetchSpy).not.toHaveBeenCalled();
        fetchSpy.mockRestore();
    });

    test('looks a barcode up and shows the product', async () => {
        const fetchSpy = vi
            .spyOn(globalThis, 'fetch')
            .mockResolvedValue(
                new Response(JSON.stringify(result()), { status: 200 }),
            );
        render(<BarcodeScanner locations={['fridge']} />);
        fireEvent.change(screen.getByLabelText('Barcode'), {
            target: { value: FAGE },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Look up' }));

        expect(
            await screen.findByText('Total 2% στραγγιστό γιαούρτι'),
        ).toBeInTheDocument();
        expect(fetchSpy).toHaveBeenCalledWith(
            `/kitchen/barcodes/${FAGE}`,
            expect.anything(),
        );
        fetchSpy.mockRestore();
    });

    test('a new product: adds a pack with its name and place', () => {
        render(
            <ScanResultView
                result={result()}
                locations={['fridge', 'pantry']}
                onDone={() => {}}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /Add a pack/ }));

        expect(inertiaMock.router.post).toHaveBeenCalledWith(
            `/kitchen/barcodes/${FAGE}`,
            {
                action: 'add',
                item_id: '',
                name: product.name,
                location: 'fridge',
                amount_text: '',
            },
            expect.anything(),
        );
    });

    test('suggests our own item with the same ingredient', () => {
        const ours = kitchenItem({
            id: 'ours',
            name: 'Greek yoghurt',
            ingredient: 'yogurt',
            amount_text: '200 g',
        });
        render(
            <ScanResultView
                result={result({ suggestion: ours })}
                locations={['fridge']}
                onDone={() => {}}
            />,
        );

        expect(screen.getByLabelText(/It's our Greek yoghurt/)).toBeChecked();
        expect(screen.queryByLabelText('Product name')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Add a pack/ }));
        expect(inertiaMock.router.post).toHaveBeenCalledWith(
            `/kitchen/barcodes/${FAGE}`,
            expect.objectContaining({ item_id: 'ours', action: 'add' }),
            expect.anything(),
        );
    });

    test('an unknown product needs a name before it can be added', () => {
        render(
            <ScanResultView
                result={result({ product: null })}
                locations={['pantry']}
                onDone={() => {}}
            />,
        );
        expect(
            screen.getByRole('button', { name: /Add a pack/ }),
        ).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Product name'), {
            target: { value: 'Παξιμάδια' },
        });
        expect(
            screen.getByRole('button', { name: /Add a pack/ }),
        ).toBeEnabled();
    });

    test('a known item can also be used up', () => {
        const item = kitchenItem({ name: 'Total 2%', amount_text: '1 kg' });
        render(
            <ScanResultView
                result={result({ item })}
                locations={['fridge']}
                onDone={() => {}}
            />,
        );
        expect(screen.getByText('In the kitchen: 1 kg')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Used a pack/ }));
        expect(inertiaMock.router.post).toHaveBeenCalledWith(
            `/kitchen/barcodes/${FAGE}`,
            expect.objectContaining({ action: 'use', name: '', location: '' }),
            expect.anything(),
        );
    });
});
