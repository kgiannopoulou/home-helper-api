<?php

namespace App\Support;

/**
 * The same as normalizeName() in the phone's homeCore.ts: "Tomatoes", "tomato " and
 * "TOMATO" are one item, so the list never gets the same thing twice.
 */
class ItemName
{
    public static function normalize(string $name): string
    {
        $n = (string) preg_replace('/\s+/', ' ', mb_strtolower(trim($name)));
        $length = mb_strlen($n);

        return match (true) {
            str_ends_with($n, 'ies') && $length > 4 => mb_substr($n, 0, -3).'y',
            str_ends_with($n, 'oes') => mb_substr($n, 0, -2),
            str_ends_with($n, 's') && ! str_ends_with($n, 'ss') && $length > 3 => mb_substr($n, 0, -1),
            default => $n,
        };
    }
}
