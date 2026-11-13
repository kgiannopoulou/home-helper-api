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
