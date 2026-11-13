import type { KitchenItem, ScanResult } from '@/types/kitchen';

/**
 * A product barcode with a correct check digit: EAN-13, EAN-8, UPC-A,
 * GTIN-14. The same rule as App\Kitchen\Barcodes\Gtin, so a misread from
 * the camera is dropped before it's sent anywhere.
 */
export function isBarcode(code: string): boolean {
    const digits = code.replace(/[\s-]/g, '');

    if (!/^\d+$/.test(digits) || ![8, 12, 13, 14].includes(digits.length)) {
        return false;
    }
    let sum = 0;
    let weight = 3;
    for (let i = digits.length - 2; i >= 0; i--) {
        sum += Number(digits[i]) * weight;
        weight = weight === 3 ? 1 : 3;
    }

    return (10 - (sum % 10)) % 10 === Number(digits[digits.length - 1]);
}

/** The JSON from GET /kitchen/barcodes/{code} */
export function scanResult(body: {
    gtin: string;
    item: KitchenItem | null;
    suggestion: KitchenItem | null;
    product: ScanResult['product'];
    lookup_error: string | null;
}): ScanResult {
    return {
        gtin: body.gtin,
        item: body.item ?? null,
        suggestion: body.suggestion ?? null,
        product: body.product ?? null,
        lookup_error: body.lookup_error ?? null,
    };
}
