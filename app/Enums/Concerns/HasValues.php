<?php

namespace App\Enums\Concerns;

trait HasValues
{
    /**
     * The backing values, for enum columns in migrations and validation rules.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
