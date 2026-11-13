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
