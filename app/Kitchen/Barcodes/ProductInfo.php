<?php

namespace App\Kitchen\Barcodes;

use App\Enums\ItemCategory;
use App\Enums\StorageLocation;

/**
 * What a barcode says about a pack: enough to add it to the kitchen in one
 * tap. Amount and unit are per pack, in a base unit (App\Kitchen\Units).
 */
final readonly class ProductInfo
{
    public function __construct(
        public string $gtin,
        public string $name,
        public ?string $brand,
        public ?string $quantityText,
        public ?float $amount,
        public ?string $unit,
        public string $ingredient,
        public ItemCategory $category,
        public StorageLocation $location,
        public ?string $imageUrl,
        public string $source,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'gtin' => $this->gtin,
            'name' => $this->name,
            'brand' => $this->brand,
            'quantity' => $this->quantityText,
            'amount' => $this->amount,
            'unit' => $this->unit,
            'ingredient' => $this->ingredient,
            'category' => $this->category->value,
            'location' => $this->location->value,
            'image_url' => $this->imageUrl,
            'source' => $this->source,
        ];
    }
}
