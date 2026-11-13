<?php

use App\Enums\ExpenseSource;
use App\Enums\HouseholdRole;
use App\Enums\ItemCategory;
use App\Enums\StorageLocation;
use App\Enums\TripSource;
use App\Kitchen\Receipts\Ocr;
use App\Kitchen\Receipts\OcrFailed;
use App\Kitchen\Receipts\Receipts;
use App\Kitchen\Receipts\TesseractOcr;
use App\Models\Expense;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\ItemPrice;
use App\Models\KitchenMovement;
use App\Models\Purchase;
use App\Models\Receipt;
use App\Models\ShoppingTrip;
use App\Models\User;
use App\Web\CurrentHousehold;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Page;
use Laravel\Sanctum\Sanctum;

const RECEIPT_TEXT = <<<'TXT'
    ΣΚΛΑΒΕΝΙΤΗΣ
    13/11/2026 18:42
    ΓΑΛΑ ΦΡΕΣΚΟ 1L            1,89 Β
    2 X 1,15
    ΜΑΚΑΡΟΝΙΑ 500ΓΡ           2,30 Β
    ΦΕΤΑ ΠΟΠ 400ΓΡ            5,60 Β
    ΕΚΠΤΩΣΗ                  -0,60
    ΧΑΡΤΙ ΚΟΥΖΙΝΑΣ            3,10 Γ
    ΣΥΝΟΛΟ                   12,29
    ΚΑΡΤΑ 4111 1111 1111 1111 12,29
    TXT;

/** An OCR that returns what it's told, so the photo path runs without tesseract */
class FakeOcr implements Ocr
{
    public function __construct(public string $result = '', public bool $fail = false) {}

    public function text(string $imagePath): string
    {
        if ($this->fail) {
            throw new OcrFailed('The photo couldn\'t be read: too dark');
        }
        expect(is_file($imagePath))->toBeTrue();

        return $this->result;
    }

    public function available(): bool
    {
        return true;
    }
}

beforeEach(function () {
    Storage::fake('local');
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->milk = $this->home->inventoryItems()->create(['name' => 'Milk', 'category' => ItemCategory::Drinks, 'location' => StorageLocation::Fridge, 'amount' => 200, 'unit' => 'ml']);
    $this->home->shoppingItems()->create(['name' => 'milk', 'category' => ItemCategory::Drinks]);
    $this->home->shoppingItems()->create(['name' => 'Bananas', 'category' => ItemCategory::Food]);
    $this->receipts = app(Receipts::class);
});

it('reads pasted text into a draft, matched to the household\'s items, and changes nothing else', function () {
    $r = $this->receipts->read($this->home, $this->owner, null, RECEIPT_TEXT);

    expect($r)->status->toBe('draft')->store->toBe('Sklavenitis')->total->toBe(12.29)->adds_up->toBeTrue()
        ->and($r->ocr_text)->toContain('[hidden]')->not->toContain('4111')
        ->and(collect($r->lines)->firstWhere('ingredient', 'milk'))->toMatchArray(['item_id' => $this->milk->id, 'include' => true])
        ->and(collect($r->lines)->firstWhere('ingredient', 'pasta'))->toMatchArray(['item_id' => null, 'include' => true, 'amount' => 1000.0])
        ->and(collect($r->lines)->firstWhere('raw', 'ΧΑΡΤΙ ΚΟΥΖΙΝΑΣ')['include'])->toBeFalse()
        ->and(ShoppingTrip::count() + KitchenMovement::count())->toBe(0);
});

it('imports: the trip, the expense, purchases and prices, the kitchen, the list', function () {
    $r = $this->receipts->import($this->receipts->read($this->home, $this->owner, null, RECEIPT_TEXT), $this->owner);

    $trip = ShoppingTrip::sole();
    expect($r)->status->toBe('imported')->shopping_trip_id->toBe($trip->id)
        ->and($trip)->store->toBe('Sklavenitis')->source->toBe(TripSource::Receipt)->date->toDateString()->toBe('2026-11-13')
        ->and((float) $trip->total)->toBe(12.29)
        ->and(Expense::sole())->source->toBe(ExpenseSource::Shopping)->note->toBe('Sklavenitis')
        // Three food lines; the kitchen roll stayed out
        ->and(Purchase::count())->toBe(3)
        ->and(ItemPrice::where('name', 'milk')->value('price'))->toBe('1.89')
        // Two packs of pasta: the price of one
        ->and(ItemPrice::where('name', 'like', 'μακαρονια%')->value('price'))->toBe('1.15')
        ->and($this->milk->fresh()->amount)->toBe(1200.0)
        ->and((float) InventoryItem::where('ingredient', 'pasta')->value('amount'))->toBe(1000.0)
        ->and(InventoryItem::where('ingredient', 'feta')->first())->amount->toBe(400.0)->name->toBe('Φετα ποπ 400γρ')
        // Milk was on the list: bought, so ticked. Bananas weren't on the receipt
        ->and($this->home->shoppingItems()->where('checked', true)->pluck('name')->all())->toBe(['milk']);

    expect(fn () => $this->receipts->import($r, $this->owner))->toThrow(ValidationException::class, 'already been imported');
});

it('imports what the person corrected', function () {
    $r = $this->receipts->read($this->home, $this->owner, null, RECEIPT_TEXT);
    $lines = collect($r->lines);
    $feta = $lines->search(fn ($l) => $l['ingredient'] === 'feta');
    $pasta = $lines->search(fn ($l) => $l['ingredient'] === 'pasta');

    $this->receipts->import($r, $this->owner, [
        $feta => ['include' => false],
        $pasta => ['name' => 'Spaghetti', 'location' => 'pantry', 'amount_text' => '1 kg'],
    ], ['store' => 'Sklavenitis Patision', 'purchased_on' => '2026-11-12']);

    expect(InventoryItem::where('ingredient', 'feta')->exists())->toBeFalse()
        ->and(InventoryItem::where('name', 'Spaghetti')->first())->amount->toBe(1000.0)->location->toBe(StorageLocation::Pantry)
        ->and(ShoppingTrip::sole())->store->toBe('Sklavenitis Patision')->date->toDateString()->toBe('2026-11-12');
});

it('reads a photo with the OCR and deletes the photo once it\'s imported', function () {
    app()->instance(Ocr::class, new FakeOcr(file_get_contents(base_path('tests/Fixtures/receipts/corner-shop.ocr.txt'))));
    $r = app(Receipts::class)->read($this->home, $this->owner, receiptPhoto(), null);

    expect($r)->source->toBe('photo')->adds_up->toBeTrue()->and(Storage::disk('local')->exists($r->image_path))->toBeTrue();
    $path = $r->image_path;
    app(Receipts::class)->import($r, $this->owner);
    expect(Storage::disk('local')->exists($path))->toBeFalse()->and($r->fresh()->image_path)->toBeNull();
});

it('says why a photo couldn\'t be read, and keeps no photo', function () {
    app()->instance(Ocr::class, new FakeOcr(fail: true));
    expect(fn () => app(Receipts::class)->read($this->home, $this->owner, receiptPhoto(), null))
        ->toThrow(ValidationException::class, 'too dark');
    expect(Storage::disk('local')->allFiles())->toBe([])->and(Receipt::count())->toBe(0);

    app()->instance(Ocr::class, new FakeOcr('a blurry nothing'));
    expect(fn () => app(Receipts::class)->read($this->home, $this->owner, receiptPhoto(), null))
        ->toThrow(ValidationException::class, 'No items found');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('prunes drafts nobody finished, with their photos', function () {
    app()->instance(Ocr::class, new FakeOcr(RECEIPT_TEXT));
    $r = app(Receipts::class)->read($this->home, $this->owner, receiptPhoto(), null);
    $this->travel(8)->days();
    $this->artisan('model:prune', ['--model' => [Receipt::class]])->assertSuccessful();

    expect(Receipt::count())->toBe(0)->and(Storage::disk('local')->exists($r->image_path))->toBeFalse();
});

it('reads the fixture photo with the real tesseract', function () {
    $ocr = new TesseractOcr;
    if (! $ocr->available()) {
        $this->markTestSkipped('tesseract is not installed here (ops/ocr/Dockerfile, CI installs it)');
    }
    app()->instance(Ocr::class, $ocr);
    $photo = new UploadedFile(base_path('tests/Fixtures/receipts/corner-shop.png'), 'corner-shop.png', 'image/png', null, true);
    $r = app(Receipts::class)->read($this->home, $this->owner, $photo, null);

    expect($r)->total->toBe(23.98)->adds_up->toBeTrue()->purchased_on->toDateString()->toBe('2026-11-13')
        ->and(collect($r->lines)->where('include', true)->pluck('ingredient')->all())->toBe(['milk', 'yogurt', 'pasta', 'tomato', 'feta', 'egg']);
})->group('ocr');

describe('the API and the page', function () {
    it('reads, shows and imports from the phone', function () {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson("/api/households/{$this->home->id}/kitchen/receipts", ['text' => RECEIPT_TEXT])
            ->assertCreated()->assertJsonPath('receipt.status', 'draft')->assertJsonPath('receipt.lines.0.item_name', 'Milk')
            ->json('receipt.id');
        $this->getJson("/api/households/{$this->home->id}/kitchen/receipts/{$id}")->assertOk()->assertJsonPath('receipt.adds_up', true);
        $this->postJson("/api/households/{$this->home->id}/kitchen/receipts/{$id}/import", ['lines' => [['include' => false]]])
            ->assertOk()->assertJsonPath('receipt.status', 'imported');
        expect(Purchase::count())->toBe(2);

        $this->postJson("/api/households/{$this->home->id}/kitchen/receipts", [])->assertUnprocessable()->assertJsonValidationErrors(['photo', 'text']);
        $this->postJson("/api/households/{$this->home->id}/kitchen/receipts", ['photo' => UploadedFile::fake()->create('r.pdf', 10, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('photo');
    });

    it('shows the draft on the Kitchen page and imports or discards it there', function () {
        $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]);
        $this->post('/kitchen/receipts', ['text' => RECEIPT_TEXT])->assertRedirect('/kitchen');
        $id = Receipt::sole()->id;

        $this->get('/kitchen')->assertInertia(fn (Page $p) => $p->where('receipt.id', $id)->where('receipt.store', 'Sklavenitis')->has('receipt.lines', 4));
        $this->delete("/kitchen/receipts/{$id}")->assertRedirect('/kitchen');
        $this->get('/kitchen')->assertInertia(fn (Page $p) => $p->where('receipt', null));
        expect(Receipt::sole()->status)->toBe('discarded');
    });

    it('keeps other households\' receipts out', function () {
        $other = Household::factory()->create();
        $r = $this->receipts->read($other, $this->owner, null, RECEIPT_TEXT);
        Sanctum::actingAs($this->owner);
        $this->getJson("/api/households/{$this->home->id}/kitchen/receipts/{$r->id}")->assertNotFound();
    });
});

/** The fixture receipt as an upload (no GD needed) */
function receiptPhoto(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('receipt.png', (string) file_get_contents(base_path('tests/Fixtures/receipts/corner-shop.png')));
}
