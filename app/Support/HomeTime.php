<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The household's clock (HOME_TIMEZONE): what "today" is for the jobs and the dashboard.
 */
class HomeTime
{
    public static function zone(): string
    {
        return (string) config('homehelper.timezone');
    }

    /** Today at home, at midnight. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone())->startOfDay();
    }
}
