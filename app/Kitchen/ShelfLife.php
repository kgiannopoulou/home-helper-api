<?php

namespace App\Kitchen;

use App\Enums\StorageLocation;

/**
 * How long food keeps (docs/kitchen.md#expiry), for when nobody typed the
 * date on the pack. Rough household figures, on the cautious side, from
 * the usual food-safety guidance (EFSA, the UK Food Standards Agency, the
 * USDA's FoodKeeper): a reminder to check, never a promise it's safe.
 *
 * Three numbers per ingredient: unopened in its usual place, once opened,
 * and frozen. An unknown ingredient gets its storage place's default.
 */
final class ShelfLife
{
    /** key => [days kept, days once opened, days frozen] (null: don't freeze it / opening doesn't matter) */
    public const DAYS = [
        'milk' => [7, 4, 90],
        'yogurt' => [21, 5, null],
        'feta' => [60, 10, 90],
        'cheese' => [30, 14, 180],
        'halloumi' => [60, 7, 180],
        'butter' => [60, 30, 180],
        'cream' => [14, 4, 90],
        'egg' => [28, null, null],
        'chicken' => [2, null, 270],
        'minced meat' => [1, null, 120],
        'pork' => [3, null, 180],
        'beef' => [3, null, 270],
        'fish' => [1, null, 180],
        'tuna' => [730, 3, null],
        'bread' => [4, null, 90],
        'pita' => [5, null, 90],
        'filo' => [30, 3, 270],
        'tomato' => [7, null, null],
        'tomato paste' => [540, 5, 90],
        'cucumber' => [7, null, null],
        'pepper' => [10, null, 240],
        'courgette' => [7, null, 240],
        'green beans' => [5, null, 300],
        'aubergine' => [7, null, null],
        'carrot' => [21, null, 300],
        'spinach' => [5, null, 300],
        'lettuce' => [5, null, null],
        'mushroom' => [5, null, 240],
        'potato' => [30, null, null],
        'onion' => [30, null, null],
        'garlic' => [60, null, null],
        'lemon' => [21, null, null],
        'banana' => [5, null, 90],
        'apple' => [30, null, null],
        'orange' => [21, null, null],
        'pasta' => [730, null, null],
        'rice' => [730, null, null],
        'lentils' => [365, null, null],
        'chickpeas' => [365, null, null],
        'beans' => [365, null, null],
        'flour' => [240, null, null],
        'oats' => [365, null, null],
        'olive oil' => [540, 180, null],
        'oil' => [365, 180, null],
        'coffee' => [365, 30, null],
        'tea' => [540, null, null],
        'honey' => [730, null, null],
        'sugar' => [730, null, null],
    ];

    /** For an unknown ingredient: by where it's kept */
    private const BY_LOCATION = ['fridge' => 7, 'freezer' => 180, 'pantry' => 180];

    /** Days it keeps where it is now, unopened */
    public static function days(string $ingredient, ?StorageLocation $location): int
    {
        [$kept, , $frozen] = self::DAYS[$ingredient] ?? [null, null, null];
        if ($location === StorageLocation::Freezer) {
            return $frozen ?? self::BY_LOCATION['freezer'];
        }

        return $kept ?? self::BY_LOCATION[$location->value ?? 'pantry'] ?? self::BY_LOCATION['pantry'];
    }

    /** Days it keeps once opened, or null when opening doesn't change it */
    public static function openedDays(string $ingredient): ?int
    {
        return self::DAYS[$ingredient][1] ?? null;
    }
}
