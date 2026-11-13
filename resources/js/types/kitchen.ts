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
    /** Estimated from how long it keeps (App\Kitchen\ShelfLife), not typed from the pack */
    expiry_estimated?: boolean;
    opened_on?: string | null;
    /** App\Kitchen\Expiry::status() */
    expiry?: Expiry;
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

/** One line of a receipt draft (App\Http\Controllers\Api\ReceiptController::receipt) */
export type ReceiptLine = {
    index: number;
    /** As printed, made readable */
    name: string;
    raw: string;
    /** After any discount */
    price: number;
    discount: number;
    quantity: number | null;
    amount: number | null;
    unit: 'g' | 'ml' | 'pcs' | null;
    ingredient: string;
    food: boolean;
    /** Goes to the kitchen unless unticked */
    include: boolean;
    /** The household's item it most likely is */
    item_id: string | null;
    item_name: string | null;
};

/** A receipt read from a photo or text, waiting to be checked */
export type ReceiptDraft = {
    id: string;
    status: 'draft' | 'imported' | 'discarded';
    source: 'photo' | 'text';
    store: string | null;
    purchased_on: string | null;
    total: number | null;
    /** The lines add up to the printed total */
    adds_up: boolean;
    lines: ReceiptLine[];
    shopping_trip_id: string | null;
    created_at: string;
};

export type Expiry = {
    state: 'expired' | 'today' | 'soon' | 'week' | 'ok' | 'none';
    /** Days until it goes off; negative once it has */
    days: number | null;
};

/** App\Queries\KitchenWasteQuery */
export type Waste = {
    days: number;
    items: {
        id: string;
        name: string;
        ingredient: string | null;
        amount: number;
        unit: string;
        times: number;
        value: number | null;
    }[];
    /** Euros, for the items with a price */
    value: number;
    priced: number;
};
