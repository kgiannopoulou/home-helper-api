<?php

namespace App\Kitchen\Receipts;

use App\Kitchen\Ingredients;

/**
 * What Tesseract gets wrong on a Greek till slip, and how to read it anyway
 * (docs/kitchen.md#receipts). Found by running it on a real receipt image
 * (tests/Fixtures/receipts): uppercase Greek, monospace, thermal print.
 *
 *   - Greek capitals that look Latin come out Latin: MAKAPONIA, AYTA, TO
 *   - some letters swap with their lookalikes: Γ→Τ (ΤΑΛΑ for ΓΑΛΑ), Σ→Χ,
 *     Ι→Τ, Π→Η or Ι, Ξ→Ε
 *   - a decimal comma gets a space: "23, 98"
 *
 * greek() turns all-lookalike words back into Greek. skeleton() goes further
 * for comparing only: the letters Tesseract confuses become one letter, so
 * "XTPATTIXTO" and "ΣΤΡΑΓΓΙΣΤΟ" have the same skeleton. Never shown to anyone.
 */
final class OcrText
{
    /** Latin capitals Tesseract gives for Greek ones (lower case, after folding) */
    private const LOOKALIKES = ['a' => 'α', 'b' => 'β', 'e' => 'ε', 'z' => 'ζ', 'h' => 'η', 'i' => 'ι', 'k' => 'κ', 'm' => 'μ', 'n' => 'ν', 'o' => 'ο', 'p' => 'ρ', 't' => 'τ', 'y' => 'υ', 'x' => 'χ'];

    /** Greek letters it confuses, folded into one */
    private const CONFUSED = ['γ' => 'τ', 'ι' => 'τ', 'π' => 'τ', 'η' => 'τ', 'σ' => 'χ', 'ξ' => 'ε'];

    /** One OCR line, tidied: no "23, 98", no €, single spaces */
    public static function line(string $line): string
    {
        $l = str_replace(['€', '|'], ' ', $line);
        // "23, 98" / "-0, 60" → "23,98" / "-0,60"
        $l = (string) preg_replace('/(\d)\s*([.,])\s+(\d{2})(?!\d)/u', '$1$2$3', $l);
        // "- 0,60" → "-0,60"
        $l = (string) preg_replace('/(?<![\d\p{L}])-\s+(\d)/u', '-$1', $l);

        return trim((string) preg_replace('/\s+/u', ' ', $l));
    }

    /** Folded, with words made only of lookalikes turned Greek: "makaponia xiattetι" → "μακαρονια χιαττετι" */
    public static function greek(string $text): string
    {
        $folded = Ingredients::fold($text);

        return (string) preg_replace_callback('/\p{L}+/u', function (array $m) {
            $word = $m[0];
            if (! preg_match('/[a-z]/', $word)) {
                return $word;
            }
            // A word with any Latin letter that has no Greek twin (COCA, LIDL) stays Latin
            $converted = '';
            foreach (mb_str_split($word) as $ch) {
                if (preg_match('/[a-z]/', $ch) && ! isset(self::LOOKALIKES[$ch])) {
                    return $word;
                }
                $converted .= self::LOOKALIKES[$ch] ?? $ch;
            }

            return $converted;
        }, $folded);
    }

    /** For comparing only: greek(), then the confused letters as one */
    public static function skeleton(string $text): string
    {
        // ...and a doubled letter as one: OCR adds and drops them (ΑΠΟΡΡΥΠΑΝΤΙΙΚΟ)
        return (string) preg_replace('/(\p{L})\1+/u', '$1', strtr(self::greek($text), self::CONFUSED));
    }

    /** Does $line contain $word (as whole words), allowing for OCR's mistakes? */
    public static function has(string $line, string $word): bool
    {
        return (bool) preg_match('/(?<!\p{L})'.preg_quote(self::skeleton($word), '/').'(?!\p{L})/u', self::skeleton($line));
    }
}
