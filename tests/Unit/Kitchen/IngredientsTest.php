<?php

use App\Kitchen\Ingredients;

it('gives one key per ingredient, in English and Greek', function (string $name, string $key) {
    expect(Ingredients::key($name))->toBe($key);
})->with([
    ['Milk', 'milk'],
    ['ΓΑΛΑ ΦΡΕΣΚΟ 1L', 'milk'],
    ['Greek yoghurt 2%', 'yogurt'],
    ['ΓΙΑΟΥΡΤΙ ΣΤΡΑΓΓΙΣΤΟ', 'yogurt'],
    ['Γιαούρτι', 'yogurt'],
    ['Chicken breast fillets', 'chicken'],
    ['Extra virgin olive oil', 'olive oil'],
    ['ΕΛΑΙΟΛΑΔΟ', 'olive oil'],
    ['Sunflower oil', 'oil'],
    ['Tomatoes', 'tomato'],
    ['Chopped tomatoes', 'tomato paste'],
    ['ΦΑΚΕΣ', 'lentils'],
    ['Φακές', 'lentils'],
    ['Eggs', 'egg'],
    ['ΑΥΓΑ Μ 6τεμ', 'egg'],
    ['Black pepper', 'black pepper'],
    ['Πιπεριές Φλωρίνης', 'pepper'],
    ['Πιπέρι', 'black pepper'],
    ['Κρέμα γάλακτος', 'cream'],
    // Unknown: itself, without the noise
    ['Fresh Tahini 300g', 'tahini'],
    ['Quinoa', 'quinoa'],
]);

it('knows the staples a recipe can assume', function () {
    expect(Ingredients::isStaple('salt'))->toBeTrue()
        ->and(Ingredients::isStaple('milk'))->toBeFalse();
});
