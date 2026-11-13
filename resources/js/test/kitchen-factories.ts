import type { KitchenItem } from '@/types/kitchen';

/** An inventory item for page tests: a litre of milk unless told otherwise */
export const kitchenItem = (over: Partial<KitchenItem> = {}): KitchenItem => ({
    id: '01jitem0000000000000000000',
    name: 'Milk',
    ingredient: 'milk',
    category: 'drinks',
    location: 'fridge',
    level: 'full',
    amount: 1000,
    unit: 'ml',
    full_amount: 1000,
    amount_text: '1 l',
    expires_on: null,
    barcode: null,
    ...over,
});
