<?php

namespace App\Models;

use App\Enums\ItemCategory;
use App\Enums\StorageLocation;
use App\Kitchen\Barcodes\ProductInfo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A product database answer, cached by barcode (App\Kitchen\Barcodes\Scanner).
 * Shared by every household: it's public product data (a pack of Fage Total
 * is the same for everyone) and holds nothing about who scanned it. Products
 * a household names itself stay on that household's inventory item instead.
 *
 * @property string $gtin
 * @property bool $found
 * @property string|null $name
 * @property string|null $brand
 * @property string|null $quantity
 * @property float|null $amount
 * @property string|null $unit
 * @property string|null $ingredient
 * @property ItemCategory|null $category
 * @property StorageLocation|null $location
 * @property string|null $image_url
 * @property string $source
 * @property CarbonImmutable $fetched_at
 */
class Product extends Model
{
    protected $primaryKey = 'gtin';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'found' => 'boolean',
            'amount' => 'float',
            'category' => ItemCategory::class,
            'location' => StorageLocation::class,
            'fetched_at' => 'immutable_datetime',
        ];
    }

    /** Cache an answer, found or not */
    public static function remember(string $gtin, ?ProductInfo $info): self
    {
        return self::query()->updateOrCreate(['gtin' => $gtin], [
            'found' => $info !== null,
            'name' => $info?->name,
            'brand' => $info?->brand,
            'quantity' => $info?->quantityText,
            'amount' => $info?->amount,
            'unit' => $info?->unit,
            'ingredient' => $info?->ingredient,
            'category' => $info?->category,
            'location' => $info?->location,
            'image_url' => $info?->imageUrl,
            'source' => $info->source ?? 'openfoodfacts',
            'fetched_at' => CarbonImmutable::now(),
        ]);
    }

    public function info(): ?ProductInfo
    {
        if (! $this->found || $this->name === null) {
            return null;
        }

        return new ProductInfo(
            gtin: $this->gtin,
            name: $this->name,
            brand: $this->brand,
            quantityText: $this->quantity,
            amount: $this->amount,
            unit: $this->unit,
            ingredient: (string) $this->ingredient,
            category: $this->category ?? ItemCategory::Food,
            location: $this->location ?? StorageLocation::Pantry,
            imageUrl: $this->image_url,
            source: $this->source,
        );
    }
}
