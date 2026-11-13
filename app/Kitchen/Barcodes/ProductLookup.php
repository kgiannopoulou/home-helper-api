<?php

namespace App\Kitchen\Barcodes;

/**
 * Where a barcode's product comes from. Open Food Facts by default
 * (KITCHEN_BARCODES=openfoodfacts); `none` keeps every scan on this server.
 */
interface ProductLookup
{
    /**
     * @return ProductInfo|null null: the service doesn't know this product
     *
     * @throws LookupFailed the service couldn't be asked (offline, slow, broken)
     */
    public function find(string $gtin): ?ProductInfo;
}
