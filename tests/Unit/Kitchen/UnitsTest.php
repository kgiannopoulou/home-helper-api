<?php

use App\Kitchen\Units;

it('parses amounts into base units', function (string $text, ?array $expected) {
    expect(Units::parse($text))->toBe($expected);
})->with([
    ['500 g', [500.0, 'g']],
    ['500g', [500.0, 'g']],
    ['1,5 kg', [1500.0, 'g']],
    ['1.5kg', [1500.0, 'g']],
    ['1L', [1000.0, 'ml']],
    ['750 ml', [750.0, 'ml']],
    ['33 cl', [330.0, 'ml']],
    ['2 x 500g', [1000.0, 'g']],
    ['4×125 g', [500.0, 'g']],
    ['2x1l', [2000.0, 'ml']],
    ['x6', [6.0, 'pcs']],
    ['6 eggs', [6.0, 'pcs']],
    ['6', [6.0, 'pcs']],
    ['2 tbsp', [30.0, 'ml']],
    ['1 ΚΙΛΟ', [1000.0, 'g']],
    ['ΓΑΛΑ 1 ΛΙΤΡΟ', [1000.0, 'ml']],
    ['a pinch', null],
]);

it('formats amounts for people', function (float $amount, string $unit, string $text) {
    expect(Units::format($amount, $unit))->toBe($text);
})->with([
    [1500, 'g', '1.5 kg'],
    [250, 'g', '250 g'],
    [1000, 'ml', '1 l'],
    [330, 'ml', '330 ml'],
    [6, 'pcs', '6'],
    [0.5, 'pcs', '0.5'],
]);

it('converts grams and millilitres, never pieces', function () {
    expect(Units::convert(200, 'g', 'ml'))->toBe(200.0)
        ->and(Units::convert(2, 'pcs', 'g'))->toBeNull()
        ->and(Units::compatible('pcs', 'pcs'))->toBeTrue();
});
