<?php

namespace App\Kitchen\Receipts;

/** KITCHEN_OCR=none: no photos, paste the receipt's text instead */
class NoOcr implements Ocr
{
    public function text(string $imagePath): string
    {
        throw new OcrFailed('Reading photos is switched off here. Paste the receipt\'s text instead.');
    }

    public function available(): bool
    {
        return false;
    }
}
