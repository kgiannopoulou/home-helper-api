<?php

/*
 * Home Helper's own recipes (docs/kitchen.md#recipes): everyday Greek home
 * cooking and a few staples, written for this app. Loaded by the migration
 * that creates the recipes table and by `php artisan kitchen:recipes`;
 * matched by slug, so editing this file and running the command updates them.
 *
 * Ingredients use App\Kitchen\Ingredients keys. Amounts are for `servings`;
 * "optional" ingredients don't count against a recipe; salt, pepper, oil,
 * olive oil, oregano, sugar and flour are staples a kitchen is assumed to
 * have (Ingredients::STAPLES).
 *
 * slug => [name, servings, minutes, tags, ingredients [key, amount, unit, optional?], steps]
 */
return [
    'horiatiki' => ['Greek salad (horiatiki)', 2, 10, ['vegetarian', 'quick', 'no-cook'], [
        ['tomato', 400, 'g'], ['cucumber', 1, 'pcs'], ['onion', 0.5, 'pcs'], ['feta', 150, 'g'], ['pepper', 1, 'pcs', true], ['olive oil', 40, 'ml'], ['oregano', 1, 'tsp'],
    ], "Cut the tomatoes and cucumber into chunks and the onion and pepper into thin rings.\nPut the feta on top as one slab, add oregano and plenty of olive oil."],
    'strapatsada' => ['Strapatsada (eggs with tomato)', 2, 15, ['vegetarian', 'quick'], [
        ['egg', 4, 'pcs'], ['tomato', 300, 'g'], ['feta', 80, 'g', true], ['olive oil', 30, 'ml'],
    ], "Grate the tomatoes and cook them in olive oil until the water has gone, about 8 minutes.\nStir in the beaten eggs on a low heat until just set; crumble the feta over."],
    'omelette' => ['Cheese omelette', 1, 10, ['vegetarian', 'quick'], [
        ['egg', 3, 'pcs'], ['cheese', 40, 'g'], ['milk', 30, 'ml', true], ['butter', 10, 'g', true],
    ], "Beat the eggs with the milk, salt and pepper.\nCook in butter on a medium heat, add the grated cheese, fold and serve."],
    'fakes' => ['Lentil soup (fakes)', 4, 50, ['vegan', 'greek'], [
        ['lentils', 500, 'g'], ['onion', 1, 'pcs'], ['carrot', 1, 'pcs'], ['garlic', 2, 'pcs'], ['tomato paste', 2, 'tbsp'], ['olive oil', 60, 'ml'],
    ], "Rinse the lentils, cover with water, bring to the boil, drain.\nAdd fresh water, the chopped onion, carrot and garlic and simmer 30 minutes.\nStir in the tomato paste and olive oil and simmer 10 minutes more. A splash of vinegar at the table."],
    'fasolada' => ['Bean soup (fasolada)', 4, 90, ['vegan', 'greek'], [
        ['beans', 500, 'g'], ['onion', 1, 'pcs'], ['carrot', 2, 'pcs'], ['tomato paste', 2, 'tbsp'], ['olive oil', 80, 'ml'],
    ], "Soak the beans overnight, boil 10 minutes and drain.\nSimmer with fresh water, the onion and carrots for an hour until soft.\nAdd tomato paste and olive oil and cook 15 minutes more."],
    'revithia' => ['Chickpea soup (revithia)', 4, 60, ['vegan', 'greek'], [
        ['chickpeas', 500, 'g'], ['onion', 2, 'pcs'], ['lemon', 1, 'pcs'], ['olive oil', 80, 'ml'],
    ], "Soak the chickpeas overnight. Simmer with the sliced onions until creamy, about 50 minutes.\nFinish with olive oil and lemon juice."],
    'spanakorizo' => ['Spinach rice (spanakorizo)', 4, 35, ['vegan', 'greek'], [
        ['spinach', 500, 'g'], ['rice', 250, 'g'], ['onion', 1, 'pcs'], ['lemon', 1, 'pcs'], ['olive oil', 60, 'ml'], ['feta', 100, 'g', true],
    ], "Soften the onion in olive oil, add the washed spinach until it wilts.\nAdd the rice and 500 ml water and simmer 18 minutes.\nLemon juice on top; feta on the side."],
    'briam' => ['Roast vegetables (briam)', 4, 75, ['vegan', 'greek'], [
        ['potato', 500, 'g'], ['courgette', 2, 'pcs'], ['aubergine', 1, 'pcs'], ['tomato', 400, 'g'], ['onion', 1, 'pcs'], ['olive oil', 100, 'ml'],
    ], "Slice everything, toss with olive oil, salt and oregano in a baking tin.\nRoast at 190 °C for about an hour, turning once."],
    'gemista' => ['Stuffed tomatoes and peppers (gemista)', 4, 90, ['vegan', 'greek'], [
        ['tomato', 6, 'pcs'], ['pepper', 2, 'pcs'], ['rice', 200, 'g'], ['onion', 1, 'pcs'], ['potato', 400, 'g', true], ['olive oil', 100, 'ml'],
    ], "Hollow out the tomatoes and peppers; mix the tomato flesh with rice, grated onion, olive oil and herbs.\nFill, put the lids back, tuck potato wedges around and bake at 180 °C for 75 minutes."],
    'kotopoulo-lemonato' => ['Lemon chicken with potatoes', 4, 70, ['greek'], [
        ['chicken', 1000, 'g'], ['potato', 1000, 'g'], ['lemon', 2, 'pcs'], ['garlic', 3, 'pcs'], ['olive oil', 80, 'ml'], ['oregano', 1, 'tbsp'],
    ], "Put the chicken and potato wedges in a tin with garlic, lemon juice, olive oil, oregano and a glass of water.\nRoast at 200 °C for about an hour, turning halfway."],
    'avgolemono' => ['Chicken soup avgolemono', 4, 60, ['greek'], [
        ['chicken', 800, 'g'], ['rice', 150, 'g'], ['egg', 2, 'pcs'], ['lemon', 2, 'pcs'], ['onion', 1, 'pcs'], ['carrot', 1, 'pcs', true],
    ], "Simmer the chicken with the onion and carrot for 40 minutes; take it out and add the rice.\nBeat the eggs with the lemon juice, slowly add hot broth, then stir it all back in off the heat."],
    'giouvetsi' => ['Orzo with chicken (giouvetsi)', 4, 60, ['greek'], [
        ['chicken', 800, 'g'], ['pasta', 400, 'g'], ['tomato paste', 3, 'tbsp'], ['onion', 1, 'pcs'], ['cheese', 80, 'g', true], ['olive oil', 50, 'ml'],
    ], "Brown the chicken and onion in olive oil, add tomato paste and water, and bake 30 minutes at 190 °C.\nAdd the orzo (kritharaki) and hot water and bake 20 minutes more, stirring once. Grated cheese on top."],
    'makaronia-kima' => ['Spaghetti with mince sauce', 4, 45, [], [
        ['pasta', 500, 'g'], ['minced meat', 500, 'g'], ['tomato paste', 400, 'g'], ['onion', 1, 'pcs'], ['carrot', 1, 'pcs', true], ['cheese', 60, 'g', true],
    ], "Brown the mince with the onion, add the tomato and simmer 30 minutes.\nCook the spaghetti; serve with the sauce and grated cheese."],
    'pasta-napoli' => ['Pasta with tomato sauce', 2, 20, ['vegan', 'quick'], [
        ['pasta', 250, 'g'], ['tomato paste', 400, 'g'], ['garlic', 2, 'pcs'], ['olive oil', 30, 'ml'], ['cheese', 40, 'g', true],
    ], "Soften the garlic in olive oil, add the tomatoes and simmer 15 minutes.\nToss with the cooked pasta."],
    'tuna-pasta-salad' => ['Tuna pasta salad', 2, 20, ['quick'], [
        ['pasta', 200, 'g'], ['tuna', 160, 'g'], ['tomato', 200, 'g'], ['cucumber', 0.5, 'pcs', true], ['lemon', 0.5, 'pcs', true], ['olive oil', 30, 'ml'],
    ], 'Cook and cool the pasta. Mix with the tuna, chopped tomato and cucumber, olive oil and lemon.'],
    'tzatziki' => ['Tzatziki', 4, 10, ['vegetarian', 'quick', 'no-cook', 'greek'], [
        ['yogurt', 500, 'g'], ['cucumber', 1, 'pcs'], ['garlic', 2, 'pcs'], ['olive oil', 20, 'ml'],
    ], 'Grate the cucumber and squeeze out the water. Mix with the yoghurt, crushed garlic, olive oil and salt.'],
    'yogurt-honey' => ['Yoghurt with honey and fruit', 1, 5, ['vegetarian', 'quick', 'no-cook', 'breakfast'], [
        ['yogurt', 200, 'g'], ['honey', 1, 'tbsp'], ['banana', 1, 'pcs', true], ['apple', 1, 'pcs', true],
    ], 'Yoghurt in a bowl, honey on top, sliced fruit if there is some.'],
    'overnight-oats' => ['Overnight oats', 1, 5, ['vegetarian', 'no-cook', 'breakfast'], [
        ['oats', 50, 'g'], ['milk', 150, 'ml'], ['yogurt', 50, 'g', true], ['banana', 1, 'pcs', true], ['honey', 1, 'tsp', true],
    ], 'Stir the oats into the milk and yoghurt and leave in the fridge overnight. Banana and honey in the morning.'],
    'banana-pancakes' => ['Banana pancakes', 2, 15, ['vegetarian', 'quick', 'breakfast'], [
        ['banana', 2, 'pcs'], ['egg', 2, 'pcs'], ['oats', 40, 'g', true], ['honey', 1, 'tbsp', true],
    ], 'Mash the bananas, beat in the eggs (and oats). Fry small pancakes on a medium heat, 2 minutes a side.'],
    'french-toast' => ['French toast', 2, 15, ['vegetarian', 'quick', 'breakfast'], [
        ['bread', 4, 'pcs'], ['egg', 2, 'pcs'], ['milk', 100, 'ml'], ['butter', 20, 'g', true], ['honey', 1, 'tbsp', true],
    ], 'Beat the eggs with the milk, soak the slices and fry in butter until golden. Honey on top.'],
    'halloumi-toast' => ['Halloumi and tomato toast', 2, 10, ['vegetarian', 'quick'], [
        ['bread', 2, 'pcs'], ['halloumi', 120, 'g'], ['tomato', 1, 'pcs'], ['olive oil', 10, 'ml'],
    ], 'Fry slices of halloumi until brown. Toast the bread, add sliced tomato and the halloumi.'],
    'spanakopita' => ['Spinach pie (spanakopita)', 6, 80, ['vegetarian', 'greek'], [
        ['filo', 450, 'g'], ['spinach', 1000, 'g'], ['feta', 300, 'g'], ['onion', 2, 'pcs'], ['egg', 2, 'pcs'], ['olive oil', 120, 'ml'],
    ], "Wilt the spinach with the onions and drain well. Mix with crumbled feta and eggs.\nLayer oiled filo, the filling, more filo; score and bake at 180 °C for 50 minutes."],
    'feta-bouyiourdi' => ['Baked feta (bouyiourdi)', 2, 25, ['vegetarian', 'greek'], [
        ['feta', 200, 'g'], ['tomato', 2, 'pcs'], ['pepper', 1, 'pcs', true], ['olive oil', 20, 'ml'], ['oregano', 1, 'tsp'],
    ], 'Feta in a small dish with sliced tomato and pepper, oil and oregano. Bake covered at 200 °C for 20 minutes. Bread for the juices.'],
    'psari-plaki' => ['Baked fish (plaki)', 4, 50, ['greek'], [
        ['fish', 800, 'g'], ['tomato', 400, 'g'], ['onion', 2, 'pcs'], ['garlic', 2, 'pcs'], ['potato', 500, 'g', true], ['olive oil', 80, 'ml'],
    ], "Soften the onions and garlic in olive oil, add the tomatoes and cook 10 minutes.\nPour over the fish in a tin and bake at 190 °C for 30 minutes."],
    'souvlaki-pita' => ['Pork souvlaki in pita', 4, 30, ['greek'], [
        ['pork', 600, 'g'], ['pita', 4, 'pcs'], ['tomato', 2, 'pcs'], ['onion', 1, 'pcs'], ['yogurt', 150, 'g', true], ['lemon', 1, 'pcs', true],
    ], "Cut the pork into cubes, marinate in oil, lemon and oregano and grill on skewers.\nWarm the pitas, fill with the meat, tomato, onion and tzatziki."],
    'mushroom-risotto' => ['Mushroom risotto', 2, 35, ['vegetarian'], [
        ['rice', 200, 'g'], ['mushroom', 250, 'g'], ['onion', 1, 'pcs'], ['butter', 30, 'g'], ['cheese', 40, 'g', true],
    ], "Soften the onion in butter, fry the mushrooms, then stir in the rice.\nAdd hot water a ladle at a time for 18 minutes, stirring. Finish with butter and cheese."],
    'rizogalo' => ['Rice pudding (rizogalo)', 4, 40, ['vegetarian', 'greek', 'dessert'], [
        ['rice', 100, 'g'], ['milk', 1000, 'ml'], ['sugar', 100, 'g'], ['lemon', 1, 'pcs', true],
    ], 'Simmer the rice in water until soft, add the milk and sugar and cook, stirring, until creamy. Cinnamon on top.'],
];
