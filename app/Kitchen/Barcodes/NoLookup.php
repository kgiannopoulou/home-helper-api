<?php

namespace App\Kitchen\Barcodes;

/** KITCHEN_BARCODES=none: barcodes never leave the server; the household names each product once */
class NoLookup implements ProductLookup
{
    public function find(string $gtin): ?ProductInfo
    {
        return null;
    }
}
