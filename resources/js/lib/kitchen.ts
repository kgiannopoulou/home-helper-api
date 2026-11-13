import type { KitchenItem } from '@/types/kitchen';

/** The order the Kitchen page shows storage places in */
export const LOCATION_ORDER = ['fridge', 'freezer', 'pantry', 'other'];

export const LOCATION_LABEL: Record<string, string> = {
    fridge: 'Fridge',
    freezer: 'Freezer',
    pantry: 'Cupboards',
    bathroom: 'Bathroom',
    cleaning: 'Cleaning',
    other: 'Other',
};

/** Items by where they're kept, in LOCATION_ORDER, empty places left out */
export function byLocation(
    items: KitchenItem[],
): { location: string; items: KitchenItem[] }[] {
    const groups = new Map<string, KitchenItem[]>();

    for (const item of items) {
        const key = item.location ?? 'other';
        groups.set(key, [...(groups.get(key) ?? []), item]);
    }

    return [...groups.entries()]
        .sort(
            ([a], [b]) =>
                rank(LOCATION_ORDER.indexOf(a)) -
                rank(LOCATION_ORDER.indexOf(b)),
        )
        .map(([location, list]) => ({ location, items: list }));
}

const rank = (i: number) => (i === -1 ? 99 : i);

/** How full, 0-1: from the amount when there is one, else from the level */
export function fullness(item: KitchenItem): number {
    if (item.amount !== null && item.full_amount) {
        return Math.max(0, Math.min(1, item.amount / item.full_amount));
    }

    return { full: 1, half: 0.5, low: 0.2, empty: 0 }[item.level ?? 'full'];
}

/** 1.89 → "€1.89" */
export function euros(amount: number): string {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'EUR',
    }).format(amount);
}

/** A receipt line's amount as a hint: "1 kg", "850 g", "6" */
export function lineAmount(line: {
    amount: number | null;
    unit: string | null;
}): string {
    if (line.amount === null || line.unit === null) {
        return '';
    }
    if (line.unit === 'pcs') {
        return String(line.amount);
    }
    const big = line.amount >= 1000;
    const value = big ? line.amount / 1000 : line.amount;
    const unit = big ? (line.unit === 'g' ? 'kg' : 'l') : line.unit;

    return `${Number(value.toFixed(2))} ${unit}`;
}
