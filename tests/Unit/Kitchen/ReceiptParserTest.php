<?php

use App\Kitchen\Receipts\OcrText;
use App\Kitchen\Receipts\ReceiptParser;

function parseReceipt(string $text)
{
    return (new ReceiptParser)->parse($text);
}

/** name => [price, discount, quantity, amount, unit, ingredient, food] */
function receiptLines($receipt): array
{
    return collect($receipt->lines)->mapWithKeys(fn ($l) => [$l['ingredient'] => [$l['price'], $l['discount'], $l['quantity'], $l['amount'], $l['unit'], $l['food']]])->all();
}

it('reads Tesseract\'s real output of the fixture receipt, mistakes and all', function () {
    // What tesseract 5.3 made of tests/Fixtures/receipts/corner-shop.png: ΤΑΛΑ for ΓΑΛΑ, MAKAPONIA, "23, 98"
    $r = parseReceipt(file_get_contents(__DIR__.'/../../Fixtures/receipts/corner-shop.ocr.txt'));

    expect($r->purchasedOn->toDateString())->toBe('2026-11-13')
        ->and($r->total)->toBe(23.98)
        ->and($r->addsUp)->toBeTrue()
        ->and(receiptLines($r))->toMatchArray([
            'milk' => [1.89, 0.0, null, null, null, true],         // "1L" was read as "11,"
            'yogurt' => [4.25, 0.0, null, 1000.0, 'g', true],
            'pasta' => [2.30, 0.0, 2.0, 1000.0, 'g', true],        // 2 X 1,15 of 500 g
            'tomato' => [2.04, 0.0, 0.85, 850.0, 'g', true],       // 0,850 KG X 2,40
            'feta' => [5.00, 0.6, null, 400.0, 'g', true],         // the discount line under it
            'egg' => [2.95, 0.0, null, 6.0, 'pcs', true],
        ])
        ->and(collect($r->lines)->where('food', false)->count())->toBe(2);  // kitchen roll, washing-up liquid
});

it('reads the same receipt as printed', function () {
    $r = parseReceipt(<<<'TXT'
        ΣΚΛΑΒΕΝΙΤΗΣ
        ΑΦΜ 099999999
        13-11-26 18:42
        ΓΑΛΑ ΦΡΕΣΚΟ 1L            1,89 Β
        2 X 1,15
        ΜΑΚΑΡΟΝΙΑ 500ΓΡ           2,30 Β
        ΦΕΤΑ ΠΟΠ 400ΓΡ            5,60 Β
        ΕΚΠΤΩΣΗ                  -0,60
        ΣΑΚΟΥΛΑ ΜΕΤΑΦΟΡΑΣ         0,09 Γ
        ΜΕΡΙΚΟ ΣΥΝΟΛΟ             9,28
        ΣΥΝΟΛΟ                    9,28
        ΚΑΡΤΑ 4111 1111 1111 1111 9,28
        TXT);

    expect($r->store)->toBe('Sklavenitis')
        ->and($r->purchasedOn->toDateString())->toBe('2026-11-13')
        ->and($r->addsUp)->toBeTrue()
        ->and(receiptLines($r)['milk'])->toBe([1.89, 0.0, null, 1000.0, 'ml', true])
        ->and(collect($r->lines)->firstWhere('raw', 'ΣΑΚΟΥΛΑ ΜΕΤΑΦΟΡΑΣ')['food'])->toBeFalse();
});

it('puts a quantity line with the item it explains, before or after', function () {
    $after = parseReceipt("ΑΥΓΑ 6ΤΕΜ 5,90\n2 x 2,95\nΣΥΝΟΛΟ 5,90");
    $before = parseReceipt("2 x 2,95\nΑΥΓΑ 6ΤΕΜ 5,90\nΣΥΝΟΛΟ 5,90");

    expect($after->lines[0])->quantity->toBe(2.0)->amount->toBe(12.0)
        ->and($before->lines[0])->quantity->toBe(2.0)->amount->toBe(12.0);
});

it('says when the lines don\'t add up to the total', function () {
    $r = parseReceipt("ΓΑΛΑ 1,89\nΨΩΜΙ 1,20\nΣΥΝΟΛΟ 9,99");
    expect($r->addsUp)->toBeFalse()->and($r->sum)->toBe(3.09)->and($r->total)->toBe(9.99);
});

it('reads mixed-case English and Greek lines too', function () {
    $r = parseReceipt("LIDL Hellas\n12.11.2026\nGreek Yoghurt 1kg 3,49 A\nOlive oil 1 l 8,99 A\nΣύνολο 12,48");
    expect($r->store)->toBe('Lidl')->and(array_keys(receiptLines($r)))->toBe(['yogurt', 'olive oil'])
        ->and($r->lines[1])->amount->toBe(1000.0)->unit->toBe('ml');
});

it('finds no items in something that isn\'t a receipt', function () {
    expect(parseReceipt("Hello\nThis is a note")->lines)->toBe([]);
});

it('compares words the way OCR misreads them', function () {
    expect(OcrText::greek('MAKAPONIA COCA'))->toBe('μακαρονια coca')
        ->and(OcrText::skeleton('XTPATTIXTO'))->toBe(OcrText::skeleton('ΣΤΡΑΓΓΙΣΤΟ'))
        ->and(OcrText::has('ΕΚΗΤΩΣΗ -0,60', 'εκπτωση'))->toBeTrue()
        ->and(OcrText::line('ΣΥΝΟΛΟ 23, 98 €'))->toBe('ΣΥΝΟΛΟ 23,98')
        ->and(ReceiptParser::ingredientBySkeleton('ΤΑΛΑ ΦΡΕΣΚΟ'))->toBe('milk');
});

it('reads pack sizes only with a unit', function (string $name, ?array $pack) {
    expect(ReceiptParser::packSize($name))->toBe($pack);
})->with([
    ['ΓΑΛΑ 1L', [1000.0, 'ml']],
    ['ΦΕΤΑ 400ΤΡ', [400.0, 'g']],
    ['ΑΛΕΥΡΙ 1KG', [1000.0, 'g']],
    ['ΓΙΑΟΥΡΤΙ 2%', null],
    ['ΑΥΓΑ 10ΤΕΜ', [10.0, 'pcs']],
    ['ΕΛΑΙΟΛΑΔΟ 0,75 ΛΙΤ', [750.0, 'ml']],
]);
