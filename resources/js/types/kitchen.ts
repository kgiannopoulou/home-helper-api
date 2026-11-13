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
