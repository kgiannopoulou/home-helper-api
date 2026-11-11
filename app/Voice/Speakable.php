<?php

namespace App\Voice;

use Carbon\CarbonImmutable;

/**
 * An answer written for the screen, rewritten for the ear (docs/voice.md#spoken-responses).
 *
 * The model is asked to answer for speech, but a speech engine reads what it
 * gets literally, so whatever still looks like writing is turned into words:
 * list markers, markdown, links, ids, ISO dates, currency symbols and units.
 * Then it is cut at a sentence end before the style's length.
 */
class Speakable
{
    private const CURRENCY = ['€' => 'euros', '£' => 'pounds', '$' => 'dollars'];

    private const UNITS = ['°C' => ' degrees', 'km/h' => ' kilometres an hour', 'ml' => ' millilitres', 'mm' => ' millimetres', 'kg' => ' kilos', 'min' => ' minutes'];

    public static function make(string $text, int $maxChars = 600): string
    {
        $t = str_replace("\r", '', $text);

        // Links and markdown: keep the words
        $t = (string) preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $t);
        $t = (string) preg_replace('#https?://\S+#', '', $t);
        $t = (string) preg_replace('/[*_`#>]+/', '', $t);

        // A list becomes one sentence: "Milk, bread and coffee."
        $t = (string) preg_replace_callback('/(?:^|\n)((?:[ \t]*(?:[-•]|\d+[.)])[ \t]+[^\n]+(?:\n|$))+)/', function (array $m) {
            $items = array_map(fn (string $l) => rtrim(trim((string) preg_replace('/^[ \t]*(?:[-•]|\d+[.)])[ \t]+/', '', $l)), '.;'), array_filter(explode("\n", trim($m[1]))));
            $last = array_pop($items);

            return "\n".($items ? implode(', ', $items).' and '.$last : $last).".\n";
        }, $t);

        // ULIDs and ids in brackets mean nothing out loud
        $t = (string) preg_replace('/\[?\b[0-9A-HJKMNP-TV-Z]{26}\b\]?/', '', $t);

        // 2026-11-14 → Saturday 14 November
        $t = (string) preg_replace_callback('/\b(\d{4})-(\d{2})-(\d{2})\b/', fn (array $m) => CarbonImmutable::createFromDate((int) $m[1], (int) $m[2], (int) $m[3])->format('l j F'), $t);

        // €1,234.50 → 1234 euros 50
        foreach (self::CURRENCY as $symbol => $word) {
            $t = (string) preg_replace_callback('/'.preg_quote($symbol, '/').'\s?(\d[\d,]*)(?:\.(\d{2}))?/u', function (array $m) use ($word) {
                $whole = str_replace(',', '', $m[1]);
                $cents = (int) ($m[2] ?? 0);

                return "{$whole} {$word}".($cents ? " {$cents}" : '');
            }, $t);
        }
        foreach (self::UNITS as $unit => $words) {
            $t = (string) preg_replace('/(\d)\s?'.preg_quote($unit, '/').'(?![\p{L}])/u', '$1'.$words, $t);
        }
        $t = str_replace(['–', '—', ' · ', '→', '&'], [' to ', ', ', ', ', ', then ', ' and '], $t);

        // Sentences, not lines
        $t = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\n+/', '. ', $t)));
        $t = (string) preg_replace('/([.!?:])\.(\s|$)/', '$1$2', $t);
        $t = (string) preg_replace('/\s+([,.!?])/', '$1', $t);

        return self::cut($t, $maxChars);
    }

    /** At most $max characters, ending at a sentence if one ends late enough */
    private static function cut(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $head = mb_substr($text, 0, $max);
        $end = max((int) mb_strrpos($head, '. '), (int) mb_strrpos($head, '? '), (int) mb_strrpos($head, '! '));

        return $end > $max / 3 ? mb_substr($head, 0, $end + 1) : rtrim(mb_substr($head, 0, (int) mb_strrpos($head, ' '))).'…';
    }
}
