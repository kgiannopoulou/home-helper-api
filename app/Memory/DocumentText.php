<?php

namespace App\Memory;

use Illuminate\Http\UploadedFile;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * The text of an uploaded document, for the knowledge base: plain text and
 * Markdown as they are, PDFs through smalot/pdfparser (pure PHP; a scanned
 * PDF without a text layer has no text, and is refused rather than stored empty).
 */
class DocumentText
{
    public const MIMES = ['txt', 'md', 'pdf'];

    /** @throws \InvalidArgumentException when there is no text to remember */
    public function extract(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        try {
            $text = $extension === 'pdf'
                ? (new Parser)->parseFile($file->getRealPath())->getText()
                : (string) file_get_contents($file->getRealPath());
        } catch (Throwable $e) {
            throw new \InvalidArgumentException('The file could not be read: '.$e->getMessage(), previous: $e);
        }

        // Valid UTF-8, no control characters, at most two blank lines in a row
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[^\P{C}\n\t]+/u', ' ', $text) ?? $text;
        $text = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+\n/', "\n", $text) ?? $text) ?? $text);

        if (mb_strlen($text) < 10) {
            throw new \InvalidArgumentException('There is no text in this file (a scanned PDF needs a text layer).');
        }

        return mb_substr($text, 0, 500000);
    }
}
