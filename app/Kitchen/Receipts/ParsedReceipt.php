<?php

namespace App\Kitchen\Receipts;

use Carbon\CarbonImmutable;

/**
 * What ReceiptParser read. A draft: a person checks it before anything is saved.
 */
final readonly class ParsedReceipt
{
    /**
     * @param  list<array<string, mixed>>  $lines  name, raw, price, discount, quantity, weighed, unit_price, amount, unit, ingredient, food
     * @param  bool  $addsUp  the lines' prices add up to the printed total (nothing missed or misread)
     */
    public function __construct(
        public ?string $store,
        public ?CarbonImmutable $purchasedOn,
        public ?float $total,
        public array $lines,
        public bool $addsUp,
        public float $sum,
    ) {}
}
