<?php

namespace App\Providers;

use App\Kitchen\Barcodes\NoLookup;
use App\Kitchen\Barcodes\OpenFoodFacts;
use App\Kitchen\Barcodes\ProductLookup;
use App\Kitchen\Receipts\NoOcr;
use App\Kitchen\Receipts\Ocr;
use App\Kitchen\Receipts\TesseractOcr;
use Illuminate\Support\ServiceProvider;

/**
 * The kitchen (docs/kitchen.md): which outside services it uses. Each one
 * can be switched off in .env and the kitchen still works by hand.
 */
class KitchenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Barcodes (day 86): KITCHEN_BARCODES=openfoodfacts|none
        $this->app->bind(ProductLookup::class, fn () => match (config('homehelper.kitchen.barcodes.provider')) {
            'none' => new NoLookup,
            default => new OpenFoodFacts(
                (string) config('homehelper.kitchen.barcodes.url'),
                (int) config('homehelper.kitchen.barcodes.timeout_seconds'),
            ),
        });

        // Receipts (day 87): KITCHEN_OCR=tesseract|none; the photo never leaves this server
        $this->app->bind(Ocr::class, fn () => match (config('homehelper.kitchen.receipts.ocr')) {
            'none' => new NoOcr,
            default => new TesseractOcr(
                (string) config('homehelper.kitchen.receipts.tesseract'),
                (string) config('homehelper.kitchen.receipts.languages'),
                (int) config('homehelper.kitchen.receipts.timeout_seconds'),
            ),
        });
    }
}
