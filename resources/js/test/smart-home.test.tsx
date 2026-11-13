import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { groupByRoom, listNames, reading } from '@/lib/smart-home';
import SmartHome from '@/pages/smart-home';
import { inertiaMock } from '@/test/inertia';
import type { Device, HomeSummary } from '@/types/smart-home';

vi.mock('@inertiajs/react', async () => ({
    ...(await import('@/test/inertia')).inertiaMock,
    usePoll: vi.fn(),
}));

const caps = {
    switchable: false,
    dimmable: false,
    lockable: false,
    reading: false,
    contact: false,
    motion: false,
};

export const device = (over: Partial<Device> = {}): Device => ({
    id: '01jdevice00000000000000000',
    entity_id: 'light.living_room_lamp',
    name: 'Living room lamp',
    kind: 'light',
    room: 'Living room',
    room_id: null,
    state: 'off',
    state_label: 'Off',
    on: false,
    value: null,
    unit: null,
    brightness: null,
    available: true,
    imported: true,
    capabilities: { ...caps, switchable: true, dimmable: true },
    last_changed_at: new Date().toISOString(),
    ...over,
});

const summary: HomeSummary = {
    connected: true,
    status: 'connected',
    lights_on: 1,
    lights: 3,
    open: ['Front door'],
    motion: [],
    unlocked: [],
    temperature: { min: 19.5, max: 23 },
    humidity: { max: 71, room: 'Bathroom' },
    unavailable: [],
};

const connection = {
    base_url: 'http://192.168.1.20:8123',
    status: 'connected' as const,
    status_message: null,
    location_name: 'Home',
    ha_version: '2026.11.1',
    last_ok_at: new Date().toISOString(),
    last_event_at: null,
    last_synced_at: null,
    webhook_url: 'http://localhost/api/smart-home/v1/states',
};

const devices: Device[] = [
    device(),
    device({
        id: '01jdevice00000000000000001',
        entity_id: 'binary_sensor.front_door',
        name: 'Front door',
        kind: 'door',
        room: 'Hallway',
        state: 'on',
        on: true,
        state_label: 'Open',
        capabilities: { ...caps, contact: true },
    }),
    device({
        id: '01jdevice00000000000000002',
        entity_id: 'sensor.bathroom_humidity',
        name: 'Bathroom humidity',
        kind: 'humidity',
        room: 'Bathroom',
        state: '71',
        value: 71,
        unit: '%',
        state_label: '71 %',
        capabilities: { ...caps, reading: true },
    }),
];

function show(props: Partial<Parameters<typeof SmartHome>[0]> = {}) {
    return render(
        <SmartHome
            connection={connection}
            webhook={null}
            summary={summary}
            devices={devices}
            comfort={[]}
            climate={{ sensors: [], history: [] }}
            candidates={[]}
            rooms={[]}
            can={{ manage: true, control: true }}
            {...props}
        />,
    );
}

beforeEach(() => vi.mocked(inertiaMock.router.post).mockClear());

describe('the smart home page', () => {
    test('shows the house at a glance and each room', () => {
        show();
        expect(screen.getByText('1 on')).toBeInTheDocument();
        expect(screen.getByText('1 open')).toBeInTheDocument();
        expect(screen.getByText('19.5–23 °C')).toBeInTheDocument();
        expect(screen.getByText('highest: Bathroom')).toBeInTheDocument();

        for (const room of ['Bathroom', 'Hallway', 'Living room']) {
            expect(
                screen.getByRole('region', { name: room }),
            ).toBeInTheDocument();
        }
        const door = screen.getByTestId('device-binary_sensor.front_door');
        expect(within(door).getByText(/Open · just now/)).toBeInTheDocument();
        expect(
            within(
                screen.getByTestId('device-sensor.bathroom_humidity'),
            ).getByText('71 %'),
        ).toBeInTheDocument();
    });

    test('says how each room feels, and why', () => {
        show({
            comfort: [
                {
                    room: 'Bathroom',
                    temperature: 23,
                    humidity: 74,
                    dew_point: 18.1,
                    status: 'very_humid',
                    advice: 'Mould risk: air it for 10 minutes or run the dehumidifier.',
                },
            ],
        });
        const room = screen.getByTestId('comfort-Bathroom');
        expect(within(room).getByText('Very humid')).toBeInTheDocument();
        expect(within(room).getByText(/Dew point 18.1 °C/)).toBeInTheDocument();
        expect(within(room).getByText(/Mould risk/)).toBeInTheDocument();
    });

    test('offers the connect form when there is no Home Assistant yet', () => {
        show({ connection: null, summary: null, devices: [] });
        expect(
            screen.getByLabelText('Home Assistant address'),
        ).toBeInTheDocument();
        expect(screen.queryByText('Lights')).not.toBeInTheDocument();
    });

    test('imports the devices the owner ticks', () => {
        show({
            candidates: [
                device({ id: 'a1', name: 'Bedroom lamp', imported: false }),
                device({ id: 'a2', name: 'Kitchen ceiling', imported: false }),
            ],
        });
        fireEvent.click(screen.getByLabelText('Import Kitchen ceiling'));
        fireEvent.click(screen.getByRole('button', { name: /Import 1/ }));

        expect(inertiaMock.router.post).toHaveBeenCalledWith(
            expect.stringContaining('/smart-home/devices/import'),
            { devices: ['a2'] },
            expect.anything(),
        );
    });

    test('hides the import list from members', () => {
        show({
            can: { manage: false, control: true },
            candidates: [device({ id: 'a1', imported: false })],
        });
        expect(
            screen.queryByText('Found in Home Assistant'),
        ).not.toBeInTheDocument();
    });
});

describe('helpers', () => {
    test('listNames', () => {
        expect(listNames([])).toBe('');
        expect(listNames(['Front door'])).toBe('Front door');
        expect(listNames(['A', 'B'])).toBe('A and B');
        expect(listNames(['A', 'B', 'C', 'D'])).toBe('A, B and 2 more');
    });

    test('reading', () => {
        expect(reading(21.5, '°C')).toBe('21.5 °C');
        expect(reading(48, '%')).toBe('48 %');
        expect(reading(null, '%')).toBe('–');
    });

    test('groupByRoom puts devices without a room last', () => {
        const groups = groupByRoom([
            device({ room: null, id: 'x' }),
            device({ room: 'Kitchen', id: 'y' }),
            device({ room: 'Bathroom', id: 'z' }),
        ]);
        expect(groups.map(([room]) => room)).toEqual([
            'Bathroom',
            'Kitchen',
            'No room',
        ]);
    });
});
