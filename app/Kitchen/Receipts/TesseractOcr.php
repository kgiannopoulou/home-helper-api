<?php

namespace App\Kitchen\Receipts;

use Illuminate\Support\Facades\Process;

/**
 * Tesseract (https://github.com/tesseract-ocr/tesseract) on this server:
 * the photo never leaves it. Greek and English (`tesseract-ocr-ell`,
 * `tesseract-ocr-eng`), page mode 6 (one block of text), which keeps a
 * receipt's columns on one line: name, price, VAT letter.
 *
 * Run as a process with a timeout and fixed arguments; the image path is the
 * only thing that varies, and it's a file this app wrote.
 */
class TesseractOcr implements Ocr
{
    public function __construct(
        private string $binary = 'tesseract',
        private string $languages = 'ell+eng',
        private int $timeoutSeconds = 60,
    ) {}

    public function text(string $imagePath): string
    {
        if (! is_file($imagePath)) {
            throw new OcrFailed('The photo is missing.');
        }
        $result = Process::timeout($this->timeoutSeconds)
            ->run([$this->binary, $imagePath, 'stdout', '-l', $this->languages, '--psm', '6']);
        if (! $result->successful()) {
            throw new OcrFailed('The photo couldn\'t be read: '.mb_substr(trim($result->errorOutput()) ?: 'tesseract failed', 0, 200));
        }

        return $result->output();
    }

    public function available(): bool
    {
        return Process::timeout(10)->run([$this->binary, '--version'])->successful();
    }
}
