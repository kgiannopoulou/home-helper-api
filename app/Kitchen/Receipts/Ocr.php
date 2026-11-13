<?php

namespace App\Kitchen\Receipts;

/**
 * Text from a photo of a receipt. Tesseract by default (KITCHEN_OCR=tesseract);
 * `none` turns photos off and leaves pasting the text.
 */
interface Ocr
{
    /** @throws OcrFailed */
    public function text(string $imagePath): string;

    public function available(): bool;
}
