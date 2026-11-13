<?php

namespace App\Kitchen\Barcodes;

/**
 * Product barcodes (docs/kitchen.md#barcodes): EAN-13, EAN-8, UPC-A and
 * GTIN-14, the numbers under the stripes on a pack.
 *
 * A camera misreads a digit now and then; the check digit catches it before
 * anything is looked up. UPC-A (12 digits, American packs) is EAN-13 with a
 * leading zero, so both are stored as 13 digits and find the same product.
 */
final class Gtin
{
    /** The stored form, or null when it isn't a valid product barcode */
    public static function normalize(string $code): ?string
    {
        $digits = (string) preg_replace('/[\s-]/', '', $code);
        if (! preg_match('/^\d+$/', $digits) || ! in_array(strlen($digits), [8, 12, 13, 14], true)) {
            return null;
        }
        if (! self::checkDigitOk($digits)) {
            return null;
        }

        return strlen($digits) === 12 ? '0'.$digits : $digits;
    }

    /** GS1 mod-10: from the right, weights 3, 1, 3, 1... over every digit but the last */
    public static function checkDigitOk(string $digits): bool
    {
        $body = substr($digits, 0, -1);
        $sum = 0;
        $weight = 3;
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += (int) $body[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - $sum % 10) % 10 === (int) substr($digits, -1);
    }

    /**
     * Shop-internal codes (prefix 20-29: weighed fruit, the deli counter)
     * mean something only to that shop: never looked up online
     */
    public static function isInStore(string $gtin): bool
    {
        return strlen($gtin) === 13 && $gtin[0] === '2';
    }
}
