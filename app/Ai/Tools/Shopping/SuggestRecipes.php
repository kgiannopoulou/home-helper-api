<?php

namespace App\Ai\Tools\Shopping;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Queries\RecipeMatchQuery;

/**
 * "What can I cook?" (docs/kitchen.md#recipes): recipes ranked by what's in
 * the kitchen, food going off soon first. Read-only: cooking it or adding
 * what's missing to the list is done on the Kitchen page or with add_shopping_item.
 */
class SuggestRecipes extends Tool
{
    public function name(): string
    {
        return 'suggest_recipes';
    }

    public function label(): string
    {
        return 'Looked for recipes';
    }

    public function description(): string
    {
        return 'Recipes that can be cooked with what is in the kitchen now, best first: fewest missing ingredients, then the ones that use food going off soon. Each has what is missing and which soon-to-expire ingredients it uses. Use it for "what can I cook?", "what should we have for dinner?", "what do I do with the spinach?". Optional tag: vegetarian, vegan, quick, breakfast, greek, no-cook, dessert.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tag' => ['type' => 'string', 'enum' => ['vegetarian', 'vegan', 'quick', 'breakfast', 'greek', 'no-cook', 'dessert']],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'Default 5'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'tag' => ['sometimes', 'in:vegetarian,vegan,quick,breakfast,greek,no-cook,dessert'],
            'limit' => ['sometimes', 'integer', 'between:1,10'],
        ];
    }

    public function run(array $input, ToolContext $context): array
    {
        $recipes = (new RecipeMatchQuery($context->household, $context->today, $input['tag'] ?? null, (int) ($input['limit'] ?? 5)))->get();

        return [
            'recipes' => array_map(fn (array $r) => [
                'name' => $r['name'],
                'minutes' => $r['minutes'],
                'servings' => $r['servings'],
                'can_cook_now' => $r['ready'],
                'missing' => $r['missing'],
                'not_enough_of' => $r['short'],
                'uses_food_going_off_soon' => $r['uses_soon'],
            ], $recipes),
            'note' => $recipes === [] ? 'Nothing in the kitchen matches a recipe yet.' : null,
        ];
    }
}
