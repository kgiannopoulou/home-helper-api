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
    sensitive: false,
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
            openings={[]}
            events={[]}
            automations={{ rules: [], runs: [], templates: [] }}
            approvals={[]}
            notifications={{
                settings: { 'door.opened': 'night' },
                labels: { 'door.opened': 'A door opens' },
                night: { from: '23:00', to: '06:00' },
            }}
            climate={{ sensors: [], history: [] }}
            candidates={[]}
            rooms={[]}
            can={{ manage: true, control: true, approve: true }}
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

    test('lists doors and windows, open ones first, and the event log', () => {
        show({
            openings: [
                {
                    id: 'o1',
                    name: 'Bedroom window',
                    kind: 'window',
                    room: 'Bedroom',
                    open: true,
                    available: true,
                    since: new Date(Date.now() - 12 * 60_000).toISOString(),
                    openings_today: 2,
                },
            ],
            events: [
                {
                    id: 'e1',
                    type: 'window.left_open',
                    severity: 'warning',
                    message: 'Bedroom window has been open for 30 min',
                    at: new Date().toISOString(),
                },
            ],
        });
        const row = screen.getByTestId('opening-Bedroom window');
        expect(within(row).getByText('Open · 12 min ago')).toBeInTheDocument();
        expect(within(row).getByText('2× today')).toBeInTheDocument();
        expect(screen.getByText('window.left_open')).toBeInTheDocument();
    });

    test('the owner chooses which events reach the phones', () => {
        show();
        fireEvent.change(screen.getByLabelText('A door opens'), {
            target: { value: 'always' },
        });
        expect(inertiaMock.router.patch).toHaveBeenCalledWith(
            expect.stringContaining('/smart-home/notifications'),
            { notify: { 'door.opened': 'always' } },
            expect.anything(),
        );
    });

    test('lists automations in words, and switches one off', () => {
        show({
            automations: {
                rules: [
                    {
                        id: 'r1',
                        name: 'Hall light',
                        enabled: true,
                        template: null,
                        description:
                            'When Front door opens, if after dark: turn on Hallway light.',
                        trigger: {},
                        conditions: [],
                        actions: [],
                        cooldown_minutes: 1,
                        last_fired_at: null,
                    },
                ],
                templates: [
                    {
                        key: 'welcome_light',
                        name: 'Light on when you come home after dark',
                        description: 'The front door opens after sunset.',
                        ready: true,
                        devices: {
                            door: [{ id: 'd1', name: 'Front door' }],
                            light: [{ id: 'd2', name: 'Hallway light' }],
                        },
                        missing: [],
                        added: false,
                    },
                    {
                        key: 'cold_window',
                        name: 'Window open and the room is getting cold',
                        description: 'x',
                        ready: false,
                        devices: {},
                        missing: ['temperature', 'window'],
                        added: false,
                    },
                ],
                runs: [
                    {
                        id: 'u1',
                        rule: 'Hall light',
                        trigger: 'Front door: off → on',
                        status: 'skipped',
                        reason: 'the sun is not down',
                        results: [],
                        at: new Date().toISOString(),
                    },
                ],
            },
        });
        const rule = screen.getByTestId('rule-Hall light');
        expect(
            within(rule).getByText(/When Front door opens, if after dark/),
        ).toBeInTheDocument();
        expect(screen.getByText(/the sun is not down/)).toBeInTheDocument();

        const welcome = screen.getByTestId('template-welcome_light');
        expect(
            within(welcome).getByText('Uses: Front door, Hallway light'),
        ).toBeInTheDocument();
        fireEvent.click(
            within(welcome).getByRole('button', { name: /Add Light on/ }),
        );
        expect(inertiaMock.router.post).toHaveBeenCalledWith(
            expect.stringContaining(
                '/smart-home/automations/templates/welcome_light',
            ),
            {},
            expect.anything(),
        );
        expect(
            within(screen.getByTestId('template-cold_window')).getByRole(
                'button',
            ),
        ).toBeDisabled();

        fireEvent.click(screen.getByLabelText('Switch off Hall light'));
        expect(inertiaMock.router.patch).toHaveBeenCalledWith(
            expect.stringContaining('/smart-home/automations/r1'),
            { enabled: false },
            expect.anything(),
        );
    });

    test('asks the owner to approve an unlock, and shows members it is waiting', () => {
        const approvals = [
            {
                id: 'a1',
                label: 'Unlock Front door lock',
                status: 'awaiting_approval' as const,
                source: 'assistant' as const,
                requested_by: 'Nikos',
                decided_by: null,
                error: null,
                expires_at: new Date(Date.now() + 600_000).toISOString(),
                created_at: new Date().toISOString(),
            },
        ];
        const { unmount } = show({ approvals });
        expect(screen.getByText(/Nikos via the assistant/)).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Approve: Unlock Front door lock',
            }),
        );
        expect(inertiaMock.router.post).toHaveBeenCalledWith(
            expect.stringContaining('/smart-home/actions/a1/approve'),
            {},
            expect.anything(),
        );
        unmount();

        show({
            approvals,
            can: { manage: false, control: true, approve: false },
        });
        expect(screen.getByText('Waiting for the owner')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Approve/ }),
        ).not.toBeInTheDocument();
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
            can: { manage: false, control: true, approve: false },
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
