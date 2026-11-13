<?php

use App\Enums\HouseholdRole;
use App\Enums\ItemCategory;
use App\Enums\StorageLocation;
use App\Kitchen\Barcodes\OpenFoodFacts;
use App\Kitchen\Barcodes\Scanner;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\KitchenMovement;
use App\Models\Product;
use App\Models\User;
use App\Web\CurrentHousehold;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

const FAGE = '5201054017128';
const UNKNOWN = '96385074';

/** What Open Food Facts answers for a Greek strained yoghurt (trimmed to the fields asked for) */
function offYoghurt(): array
{
    return ['code' => FAGE, 'status' => 1, 'product' => [
        'product_name' => 'Total 2% στραγγιστό γιαούρτι',
        'product_name_en' => 'Total 2% strained yoghurt',
        'brands' => 'Fage,Fage International',
        'quantity' => '1 kg',
        'product_quantity' => '1000',
        'product_quantity_unit' => 'g',
        'categories_tags' => ['en:dairies', 'en:fermented-foods', 'en:yogurts', 'en:greek-style-yogurts'],
        'image_front_small_url' => 'https://images.openfoodfacts.org/images/products/520/105/401/7128/front_el.200.jpg',
    ]];
}

beforeEach(function () {
    Http::fake([
        'world.openfoodfacts.org/api/v2/product/'.FAGE.'*' => Http::response(offYoghurt()),
        'world.openfoodfacts.org/*' => Http::response(['status' => 0, 'status_verbose' => 'product not found'], 404),
    ]);
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->scanner = app(Scanner::class);
});

function offCalls(): int
{
    return collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'openfoodfacts'))->count();
}

it('reads an Open Food Facts product: name, size, ingredient, where it goes', function () {
    $info = OpenFoodFacts::product(FAGE, offYoghurt()['product']);

    expect($info->toArray())->toMatchArray([
        'name' => 'Total 2% στραγγιστό γιαούρτι', 'brand' => 'Fage', 'amount' => 1000.0, 'unit' => 'g',
        'ingredient' => 'yogurt', 'category' => 'food', 'location' => 'fridge',
    ]);
});

it('takes the ingredient from the categories when the name is a brand', function () {
    $info = OpenFoodFacts::product('1', ['product_name' => 'Δωδώνη', 'quantity' => '400 g', 'categories_tags' => ['en:dairies', 'en:cheeses', 'en:feta']]);
    expect($info)->ingredient->toBe('feta')->amount->toBe(400.0);
});

it('looks a barcode up once and caches it, found or not', function () {
    expect($this->scanner->scan($this->home, FAGE)['product']->name)->toBe('Total 2% στραγγιστό γιαούρτι');
    $this->scanner->scan($this->home, FAGE);
    expect($this->scanner->scan($this->home, UNKNOWN)['product'])->toBeNull();
    $this->scanner->scan($this->home, UNKNOWN);

    expect(offCalls())->toBe(2)
        ->and(Product::find(UNKNOWN)->found)->toBeFalse();

    // Only the barcode goes out
    Http::assertSent(fn (Request $r) => ! str_contains($r->url(), 'openfoodfacts') || (str_contains($r->url(), '/api/v2/product/') && ! $r->hasHeader('Authorization')));
});

it('asks again after a week for a product nobody knew', function () {
    $this->scanner->scan($this->home, UNKNOWN);
    $this->travel(8)->days();
    $this->scanner->scan($this->home, UNKNOWN);
    expect(offCalls())->toBe(2);
});

it('refuses a misread barcode before anything else', function () {
    expect(fn () => $this->scanner->scan($this->home, '5201054017129'))->toThrow(ValidationException::class, 'check the digits')
        ->and(offCalls())->toBe(0);
});

it('never looks up shop-internal codes', function () {
    $code = '2001234000000';
    expect($this->scanner->scan($this->home, $code)['product'])->toBeNull()->and(offCalls())->toBe(0);
});

it('keeps working when Open Food Facts is down', function () {
    Http::fake(['world.openfoodfacts.org/*' => Http::failedConnection()]);
    $scan = $this->scanner->scan($this->home, '5000112637922');
    expect($scan['product'])->toBeNull()->and($scan['lookup_error'])->toContain('not answering')
        ->and(Product::count())->toBe(0);
});

it('adds a new pack: creates the item with the barcode and the pack\'s amount', function () {
    $item = $this->scanner->apply($this->home, $this->owner, FAGE, 'add');

    expect($item)->name->toBe('Total 2% στραγγιστό γιαούρτι')->barcode->toBe(FAGE)->ingredient->toBe('yogurt')
        ->location->toBe(StorageLocation::Fridge)->amount->toBe(1000.0)->unit->toBe('g')->pack_amount->toBe(1000.0)
        ->and(KitchenMovement::sole()->note)->toBe('scanned '.FAGE);

    // The second scan finds the item by barcode: one more pack
    $this->scanner->apply($this->home, $this->owner, FAGE, 'add');
    $this->scanner->apply($this->home, $this->owner, FAGE, 'use', ['amount_text' => '200']);
    expect(InventoryItem::sole()->amount)->toBe(1800.0);
});

it('suggests the household\'s own item with the same ingredient, and joins it', function () {
    $ours = $this->home->inventoryItems()->create(['name' => 'Greek yoghurt', 'category' => ItemCategory::Food, 'location' => StorageLocation::Fridge, 'amount' => 200, 'unit' => 'g']);

    expect($this->scanner->scan($this->home, FAGE)['suggestion']?->id)->toBe($ours->id);
    $this->scanner->apply($this->home, $this->owner, FAGE, 'add', ['item_id' => $ours->id]);

    expect(InventoryItem::count())->toBe(1)
        ->and($ours->fresh())->barcode->toBe(FAGE)->amount->toBe(1200.0);
});

it('learns a product nobody knows from the household, once', function () {
    expect(fn () => $this->scanner->apply($this->home, $this->owner, UNKNOWN, 'add'))->toThrow(ValidationException::class, 'What is it?');

    $item = $this->scanner->apply($this->home, $this->owner, UNKNOWN, 'add', ['name' => 'Παξιμάδια κρίθινα', 'location' => 'pantry', 'amount_text' => '400 g']);
    expect($item)->barcode->toBe(UNKNOWN)->amount->toBe(400.0);

    // Next time: one tap, the same size
    expect($this->scanner->scan($this->home, UNKNOWN)['item']->id)->toBe($item->id);
    $this->scanner->apply($this->home, $this->owner, UNKNOWN, 'add');
    expect($item->fresh()->amount)->toBe(800.0);
});

it('can\'t use what isn\'t in the kitchen', function () {
    expect(fn () => $this->scanner->apply($this->home, $this->owner, FAGE, 'use'))->toThrow(ValidationException::class, 'add it first');
});

describe('the API and the page', function () {
    it('scans and adds from the phone', function () {
        Sanctum::actingAs($this->owner);
        $this->getJson("/api/households/{$this->home->id}/kitchen/barcodes/".FAGE)
            ->assertOk()->assertJsonPath('product.ingredient', 'yogurt')->assertJsonPath('item', null);
        $this->postJson("/api/households/{$this->home->id}/kitchen/barcodes/".FAGE, ['action' => 'add'])
            ->assertCreated()->assertJsonPath('item.amount_text', '1 kg')->assertJsonPath('item.barcode', FAGE);
        $this->getJson("/api/households/{$this->home->id}/kitchen/barcodes/123")->assertUnprocessable();
    });

    it('answers the page\'s scanner with JSON and adds from it', function () {
        $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]);
        $this->getJson('/kitchen/barcodes/'.FAGE)->assertOk()->assertJsonPath('product.name', 'Total 2% στραγγιστό γιαούρτι');
        $this->post('/kitchen/barcodes/'.FAGE, ['action' => 'add'])->assertRedirect('/kitchen');
        expect(InventoryItem::sole()->barcode)->toBe(FAGE);
    });

    it('keeps another household\'s barcodes to itself', function () {
        $other = Household::factory()->create();
        $other->inventoryItems()->create(['name' => 'Their yoghurt', 'category' => ItemCategory::Food, 'location' => StorageLocation::Fridge]);
        $other->inventoryItems()->first()->forceFill(['barcode' => UNKNOWN])->save();

        expect($this->scanner->scan($this->home, UNKNOWN)['item'])->toBeNull();
    });
});
