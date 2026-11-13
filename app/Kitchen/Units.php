<?php

namespace App\Kitchen;

/**
 * Amounts in the kitchen (docs/kitchen.md#amounts-and-units).
 *
 * Everything is stored in one of three base units: grams, millilitres or
 * pieces. "1,5 kg", "2 x 500g", "1L", "6 eggs" and "x6" all parse; recipes
 * may say tbsp, tsp or cup. Grams and millilitres convert 1:1 (water's
 * density): close enough for milk, yoghurt and oil, and documented as an
 * approximation. Pieces never convert to weight.
 */
final class Units
{
    public const BASE = ['g', 'ml', 'pcs'];

    /** unit word => [base unit, factor] */
    private const UNITS = [
        'mg' => ['g', 0.001], 'g' => ['g', 1], 'gr' => ['g', 1], 'grams' => ['g', 1], 'gram' => ['g', 1], 'γρ' => ['g', 1],
        'kg' => ['g', 1000], 'kilo' => ['g', 1000], 'kilos' => ['g', 1000], 'κιλο' => ['g', 1000], 'κιλα' => ['g', 1000],
        'ml' => ['ml', 1], 'cl' => ['ml', 10], 'dl' => ['ml', 100], 'l' => ['ml', 1000], 'lt' => ['ml', 1000], 'ltr' => ['ml', 1000], 'litre' => ['ml', 1000], 'liter' => ['ml', 1000], 'litres' => ['ml', 1000], 'λιτρο' => ['ml', 1000], 'λιτρα' => ['ml', 1000],
        'tsp' => ['ml', 5], 'tbsp' => ['ml', 15], 'cup' => ['ml', 240], 'cups' => ['ml', 240],
        'pcs' => ['pcs', 1], 'pc' => ['pcs', 1], 'piece' => ['pcs', 1], 'pieces' => ['pcs', 1], 'τεμ' => ['pcs', 1], 'τεμαχια' => ['pcs', 1],
        'x' => ['pcs', 1],
    ];

    /**
     * "500 g" → [500.0, 'g']; "2 x 1L" → [2000.0, 'ml']; "6 eggs" → [6.0, 'pcs']; null when there's no number.
     *
     * @return array{0: float, 1: string}|null
     */
    public static function parse(?string $text): ?array
    {
        if ($text === null) {
            return null;
        }
        $t = Ingredients::fold($text);
        // Decimal comma, as on Greek receipts and packs
        $t = (string) preg_replace('/(\d),(\d)/u', '$1.$2', $t);

        // 2 x 500g, 2x1l, 4 × 125 g
        if (preg_match('/(\d+(?:\.\d+)?)\s*[x×*]\s*(\d+(?:\.\d+)?)\s*([a-zα-ω]+)?/u', $t, $m)) {
            $per = self::toBase((float) $m[2], $m[3] ?? '');
            if ($per) {
                return [round($per[0] * (float) $m[1], 3), $per[1]];
            }
        }
        // x6, 6x (a pack of six)
        if (preg_match('/(?:^|\s)x\s*(\d+)\b|(?:^|\s)(\d+)\s*x(?:\s|$)/u', $t, $m)) {
            return [(float) ($m[1] !== '' ? $m[1] : $m[2]), 'pcs'];
        }
        if (! preg_match('/(\d+(?:\.\d+)?)\s*([a-zα-ω]+)?/u', $t, $m)) {
            return null;
        }

        return self::toBase((float) $m[1], $m[2] ?? '') ?? [(float) $m[1], 'pcs'];
    }

    /**
     * @return array{0: float, 1: string}|null
     */
    public static function toBase(float $amount, string $unit): ?array
    {
        $unit = Ingredients::fold($unit);
        if ($unit === '') {
            return [$amount, 'pcs'];
        }
        if (isset(self::UNITS[$unit])) {
            [$base, $factor] = self::UNITS[$unit];

            return [round($amount * $factor, 3), $base];
        }

        // "6 eggs": a word that isn't a unit counts pieces
        return preg_match('/^[a-zα-ω]{3,}$/u', $unit) ? [$amount, 'pcs'] : null;
    }

    /** Can an amount in $from be compared with one in $to? */
    public static function compatible(string $from, string $to): bool
    {
        return $from === $to || (in_array($from, ['g', 'ml'], true) && in_array($to, ['g', 'ml'], true));
    }

    /** $amount in base unit $from, as base unit $to (g ↔ ml at 1:1), or null */
    public static function convert(float $amount, string $from, string $to): ?float
    {
        return self::compatible($from, $to) ? $amount : null;
    }

    /** 1500, 'g' → "1.5 kg"; 250, 'ml' → "250 ml"; 6, 'pcs' → "6" */
    public static function format(?float $amount, ?string $unit): string
    {
        if ($amount === null || $unit === null) {
            return '';
        }
        $n = fn (float $v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');

        return match (true) {
            $unit === 'g' && abs($amount) >= 1000 => $n($amount / 1000).' kg',
            $unit === 'ml' && abs($amount) >= 1000 => $n($amount / 1000).' l',
            $unit === 'pcs' => $n($amount),
            default => $n($amount).' '.$unit,
        };
    }
}
