<?php

use App\Kitchen\Barcodes\Gtin;

it('accepts real barcodes and stores UPC-A as EAN-13', function (string $code, ?string $stored) {
    expect(Gtin::normalize($code))->toBe($stored);
})->with([
    'EAN-13, a Greek pack (520)' => ['5201054017128', '5201054017128'],
    'EAN-13 with spaces' => ['5 201054 017128', '5201054017128'],
    'EAN-8' => ['96385074', '96385074'],
    'UPC-A' => ['036000291452', '0036000291452'],
    'GTIN-14' => ['15201054017125', '15201054017125'],
    'one digit misread' => ['5201054017129', null],
    'too short' => ['12345', null],
    'letters' => ['52010540171AB', null],
]);

it('knows shop-internal codes', function () {
    expect(Gtin::isInStore('2001234000000'))->toBeTrue()
        ->and(Gtin::isInStore('5201054017128'))->toBeFalse();
});
