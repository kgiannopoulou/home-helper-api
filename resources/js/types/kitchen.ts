/** An inventory item on the Kitchen page (App\Http\Controllers\Web\KitchenController::item) */
export type KitchenItem = {
    id: string;
    name: string;
    /** App\Kitchen\Ingredients::key(): what recipes and receipts match on */
    ingredient: string | null;
    category: string;
    location: string | null;
    level: 'full' | 'half' | 'low' | 'empty' | null;
    /** In the base unit: grams, millilitres or pieces */
    amount: number | null;
    unit: 'g' | 'ml' | 'pcs' | null;
    full_amount: number | null;
    /** "750 ml", or the phone's free text */
    amount_text: string;
    expires_on: string | null;
    /** The pack it was scanned as */
    barcode: string | null;
};

export type MovementKind =
    | 'bought'
    | 'used'
    | 'cooked'
    | 'wasted'
    | 'expired'
    | 'adjusted';

/** One change of an amount (App\Models\KitchenMovement) */
export type Movement = {
    id: string;
    item_id: string;
    item: string;
    kind: MovementKind;
    amount: number;
    unit: 'g' | 'ml' | 'pcs';
    /** "−250 ml" */
    label: string;
    by: string | null;
    note: string | null;
    occurred_at: string;
};

/** A barcode's product (App\Kitchen\Barcodes\ProductInfo) */
export type Product = {
    gtin: string;
    name: string;
    brand: string | null;
    /** As printed on the pack: "1 kg" */
    quantity: string | null;
    amount: number | null;
    unit: 'g' | 'ml' | 'pcs' | null;
    ingredient: string;
    category: string;
    location: string;
    image_url: string | null;
    source: string;
};

/** GET /kitchen/barcodes/{code} (App\Kitchen\Barcodes\Scanner::scan) */
export type ScanResult = {
    gtin: string;
    /** The household's item with this barcode */
    item: KitchenItem | null;
    /** No item yet, but one with the same ingredient: "is this it?" */
    suggestion: KitchenItem | null;
    product: Product | null;
    /** Open Food Facts couldn't be asked */
    lookup_error: string | null;
};
