<?php

namespace App\Support;

/**
 * Money for push and email text, like wholeMoney() on the phone: "€1,180".
 */
class Money
{
    private const SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];

    public static function whole(float $amount, string $currency): string
    {
        $number = number_format(round($amount));

        return isset(self::SYMBOLS[$currency]) ? self::SYMBOLS[$currency].$number : "{$number} {$currency}";
    }

    /** "€1,180.40", for briefings and summaries */
    public static function exact(float $amount, string $currency): string
    {
        $number = number_format($amount, 2);

        return isset(self::SYMBOLS[$currency]) ? self::SYMBOLS[$currency].$number : "{$number} {$currency}";
    }
}
