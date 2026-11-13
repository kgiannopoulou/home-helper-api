<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Who put an item on the shopping list. */
enum ShoppingSource: string
{
    use HasValues;

    case Manual = 'manual';
    case Inventory = 'inventory';
    // "Add what's missing" from a recipe or the meal plan (Phase 7)
    case Recipe = 'recipe';
    // Kept up to date by the meal plan (App\Kitchen\Planning\PlanShoppingList)
    case MealPlan = 'meal_plan';
}
