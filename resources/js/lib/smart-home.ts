import type { Device, DeviceKind } from '@/types/smart-home';

export const KIND_LABEL: Record<DeviceKind, string> = {
    light: 'Light',
    switch: 'Switch',
    lock: 'Lock',
    temperature: 'Temperature',
    humidity: 'Humidity',
    door: 'Door',
    window: 'Window',
    motion: 'Motion',
    sensor: 'Sensor',
    binary_sensor: 'Sensor',
    cover: 'Blind',
    climate: 'Climate',
    fan: 'Fan',
};

/** Devices by room, rooms in name order, "No room" last */
export function groupByRoom(devices: Device[]): [string, Device[]][] {
    const groups = new Map<string, Device[]>();
    for (const d of devices) {
        const room = d.room ?? 'No room';
        groups.set(room, [...(groups.get(room) ?? []), d]);
    }

    return [...groups.entries()].sort(([a], [b]) =>
        a === 'No room' ? 1 : b === 'No room' ? -1 : a.localeCompare(b),
    );
}

/** "Front door", "Front door and Bedroom window", "Front door, Bedroom window and 2 more" */
export function listNames(names: string[], max = 2): string {
    if (names.length <= 1) {
        return names[0] ?? '';
    }
    if (names.length <= max) {
        return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
    }

    return `${names.slice(0, max).join(', ')} and ${names.length - max} more`;
}

/** 21.5 → "21.5", 21 → "21" */
export function reading(value: number | null, unit: string | null): string {
    if (value === null) {
        return '–';
    }
    const v = Number.isInteger(value) ? String(value) : value.toFixed(1);

    return unit ? `${v} ${unit}` : v;
}
