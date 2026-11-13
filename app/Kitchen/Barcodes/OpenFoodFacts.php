<?php

namespace App\Kitchen\Barcodes;

use App\Enums\ItemCategory;
use App\Enums\StorageLocation;
use App\Kitchen\Ingredients;
use App\Kitchen\Units;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Open Food Facts (https://world.openfoodfacts.org), the open product
 * database: GET /api/v2/product/{barcode}. Only the barcode is sent; no
 * account, no key. Greek packs are well covered, often with a Greek name.
 *
 * Its categories decide where a pack goes: dairy, meat and fresh things to
 * the fridge, frozen to the freezer, the rest to the cupboard.
 */
class OpenFoodFacts implements ProductLookup
{
    private const FIELDS = 'code,product_name,product_name_en,product_name_el,generic_name,brands,quantity,product_quantity,product_quantity_unit,categories_tags,image_front_small_url';

    public function __construct(private string $baseUrl, private int $timeoutSeconds = 5) {}

    public function find(string $gtin): ?ProductInfo
    {
        try {
            $response = Http::acceptJson()
                ->timeout($this->timeoutSeconds)
                ->connectTimeout(min(3, $this->timeoutSeconds))
                // They ask apps to say who they are; no personal details
                ->withUserAgent('HomeHelper/1.0 (https://github.com/kgiannopoulou/home-helper-api)')
                ->get(rtrim($this->baseUrl, '/')."/api/v2/product/{$gtin}.json", ['fields' => self::FIELDS]);
        } catch (ConnectionException $e) {
            throw new LookupFailed('Open Food Facts is not answering.', 0, $e);
        }
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new LookupFailed("Open Food Facts answered HTTP {$response->status()}.");
        }
        $body = $response->json();
        if ((int) ($body['status'] ?? 0) !== 1 || ! is_array($body['product'] ?? null)) {
            return null;
        }

        return self::product($gtin, $body['product']);
    }

    /** @param array<string, mixed> $p */
    public static function product(string $gtin, array $p): ?ProductInfo
    {
        $name = self::text($p['product_name'] ?? null) ?? self::text($p['product_name_el'] ?? null)
            ?? self::text($p['product_name_en'] ?? null) ?? self::text($p['generic_name'] ?? null);
        if ($name === null) {
            return null;
        }
        $brand = self::text(explode(',', (string) ($p['brands'] ?? ''))[0]);
        $quantity = self::text($p['quantity'] ?? null);
        $amount = null;
        $unit = null;
        if (is_numeric($p['product_quantity'] ?? null) && ($base = Units::toBase((float) $p['product_quantity'], (string) ($p['product_quantity_unit'] ?? 'g')))) {
            [$amount, $unit] = $base;
        } elseif ($parsed = Units::parse($quantity)) {
            [$amount, $unit] = $parsed;
        }
        $tags = array_filter((array) ($p['categories_tags'] ?? []), 'is_string');
        // The English name is the better key ("Strained yoghurt"), the shown name the local one
        $keyName = self::text($p['product_name_en'] ?? null) ?? $name;
        $ingredient = Ingredients::key($keyName);
        // A brand name ("Δέλτα Daily") isn't an ingredient; the categories may know better
        if (! array_key_exists($ingredient, Ingredients::ALIASES)) {
            $ingredient = self::ingredientFromTags($tags) ?? $ingredient;
        }

        return new ProductInfo(
            gtin: $gtin,
            name: mb_substr($name, 0, 100),
            brand: $brand ? mb_substr($brand, 0, 60) : null,
            quantityText: $quantity ? mb_substr($quantity, 0, 50) : null,
            amount: $amount && $amount > 0 ? $amount : null,
            unit: $amount && $amount > 0 ? $unit : null,
            ingredient: $ingredient,
            category: self::category($tags),
            location: self::location($tags),
            imageUrl: is_string($p['image_front_small_url'] ?? null) && str_starts_with($p['image_front_small_url'], 'https://') ? $p['image_front_small_url'] : null,
            source: 'openfoodfacts',
        );
    }

    /** @param list<string>|array<int, string> $tags */
    private static function category(array $tags): ItemCategory
    {
        foreach ($tags as $t) {
            if (preg_match('/^en:(beverages|waters|coffees|teas|juices|sodas|wines|beers)/', $t)) {
                return ItemCategory::Drinks;
            }
        }

        return ItemCategory::Food;
    }

    /** @param list<string>|array<int, string> $tags */
    private static function location(array $tags): StorageLocation
    {
        $all = implode(' ', $tags);

        return match (true) {
            (bool) preg_match('/en:frozen/', $all) => StorageLocation::Freezer,
            (bool) preg_match('/en:(dairies|milks|yogurts|cheeses|meats|poultries|fishes|seafood|fresh|eggs|butters|creams|hams|sausages)/', $all) => StorageLocation::Fridge,
            default => StorageLocation::Pantry,
        };
    }

    /**
     * An ingredient from the most specific category Ingredients knows ("en:feta-cheeses" → feta)
     *
     * @param  array<int, string>  $tags
     */
    private static function ingredientFromTags(array $tags): ?string
    {
        foreach (array_reverse($tags) as $t) {
            $words = str_replace('-', ' ', (string) preg_replace('/^[a-z]{2}:/', '', $t));
            $key = Ingredients::key($words);
            if (array_key_exists($key, Ingredients::ALIASES)) {
                return $key;
            }
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $v === '' ? null : $v;
    }
}
