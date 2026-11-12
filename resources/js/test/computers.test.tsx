import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { ago, bytes, level, paramError } from '@/lib/computers';
import Computers from '@/pages/computers';
import { posts } from '@/test/inertia';
import type { Computer, ComputerCommand } from '@/types/computers';

vi.mock('@inertiajs/react', async () => ({
    ...(await import('@/test/inertia')).inertiaMock,
    usePoll: vi.fn(),
}));

const computer = (over: Partial<Computer> = {}): Computer => ({
    id: '01jagent000000000000000000',
    name: 'Mini PC',
    hostname: 'MINIPC',
    platform: 'windows-x64',
    version: '0.5.0',
    online: true,
    last_seen_at: new Date().toISOString(),
    started_at: null,
    os: 'Windows 10.0.26300',
    cpu_model: 'Intel(R) N100',
    cores: 4,
    cpu_percent: 12.5,
    memory: { total_bytes: 16e9, used_bytes: 4e9, percent: 25 },
    disks: [
        {
            mount: 'C:\\',
            total_bytes: 500e9,
            free_bytes: 250e9,
            used_percent: 50,
        },
        {
            mount: 'D:\\',
            total_bytes: 1000e9,
            free_bytes: 30e9,
            used_percent: 97,
        },
    ],
    temperatures: [{ label: 'CPU', celsius: 91 }],
    microphones: [
        {
            id: 'm1',
            name: 'Microphone (USB Audio)',
            direction: 'input',
            default: true,
            state: 'active',
        },
        {
            id: 'm2',
            name: 'External Microphone',
            direction: 'input',
            default: false,
            state: 'unplugged',
        },
    ],
    speakers: [],
    commands: [
        {
            key: 'ping_router',
            description: 'Ping a device on the home network',
            sensitive: false,
            params: [
                {
                    name: 'host',
                    description: '192.168.x.x',
                    pattern: '^192\\.168\\.[0-9]{1,3}\\.[0-9]{1,3}$',
                },
            ],
        },
        {
            key: 'restart_computer',
            description: 'Restart in one minute',
            sensitive: true,
            params: [],
        },
    ],
    outbox_pending: 0,
    update: null,
    history: [],
    can: { run: true, manage: false },
    ...over,
});

const command = (over: Partial<ComputerCommand> = {}): ComputerCommand => ({
    id: '01jcmd00000000000000000000',
    computer: 'Mini PC',
    label: 'restart_computer',
    status: 'awaiting_confirmation',
    source: 'dashboard',
    sensitive: true,
    requested_by: 'Nikos',
    confirmed_by: null,
    exit_code: null,
    output: null,
    truncated: false,
    reason: null,
    duration_ms: null,
    created_at: new Date().toISOString(),
    can_confirm: true,
    can_cancel: true,
    ...over,
});

const props = (over: Partial<Parameters<typeof Computers>[0]> = {}) => ({
    computers: [computer()],
    events: [],
    commands: [],
    can: { pair: false },
    pairing: null,
    heartbeat_seconds: 30,
    ...over,
});

beforeEach(() => {
    posts.length = 0;
});

describe('helpers', () => {
    test('sizes, times and levels read naturally', () => {
        expect(bytes(17_179_869_184)).toBe('17.2 GB');
        expect(bytes(512)).toBe('512 B');
        const now = new Date('2026-11-12T10:00:00Z');
        expect(ago('2026-11-12T09:59:50Z', now)).toBe('just now');
        expect(ago('2026-11-12T09:57:00Z', now)).toBe('3 min ago');
        expect(ago('2026-11-10T10:00:00Z', now)).toBe('2 days ago');
        expect(ago(null)).toBe('never');
        expect(level(91, 85, 95)).toBe('warn');
        expect(level(null, 85, 95)).toBe('unknown');
    });

    test('parameters are checked like the agent checks them: whole match, no options', () => {
        const pattern = '^192\\.168\\.[0-9]{1,3}\\.[0-9]{1,3}$';
        expect(paramError(pattern, '192.168.1.1')).toBeNull();
        expect(paramError(pattern, '192.168.1.1; calc')).toMatch(/Must match/);
        expect(paramError('.*', '-rf')).toMatch(/Can’t start/);
        expect(paramError(pattern, '')).toBe('Required');
    });
});

describe('the Computers page', () => {
    test('shows readings, the fullest disk, the hottest sensor and the audio devices', () => {
        render(<Computers {...props()} />);
        expect(screen.getByText('Online')).toBeInTheDocument();
        expect(screen.getByText('Disk D:\\')).toBeInTheDocument();
        expect(screen.getByText('97% used')).toBeInTheDocument();
        expect(screen.getByText('91 °C')).toBeInTheDocument();
        expect(
            screen.getByText(/Microphone \(USB Audio\)/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/External Microphone · unplugged/),
        ).toBeInTheDocument();
    });

    test('a command runs only once its parameters are valid', () => {
        render(<Computers {...props()} />);
        const run = screen.getByRole('button', {
            name: 'Run ping_router on Mini PC',
        });
        expect(run).toBeDisabled();

        const host = screen.getByLabelText(/host/);
        fireEvent.change(host, { target: { value: '192.168.1.1 && calc' } });
        expect(run).toBeDisabled();
        expect(screen.getByText(/Must match/)).toBeInTheDocument();

        fireEvent.change(host, { target: { value: '192.168.1.1' } });
        expect(run).toBeEnabled();
        fireEvent.click(run);
        expect(posts[0].url).toContain(
            '/computers/01jagent000000000000000000/commands',
        );
        expect(posts[0].data).toMatchObject({
            command: 'ping_router',
            'params[host]': '192.168.1.1',
        });
    });

    test('a sensitive command is marked, and the owner can confirm it', () => {
        render(<Computers {...props({ commands: [command()] })} />);
        expect(screen.getByText('owner confirms')).toBeInTheDocument();
        const item = screen
            .getByText('restart_computer · Mini PC')
            .closest('li')!;
        expect(
            within(item).getByText('Waiting for the owner'),
        ).toBeInTheDocument();
        expect(
            within(item).getByRole('button', { name: /Confirm/ }),
        ).toBeInTheDocument();
    });

    test('only the owner sees pairing, removing and installing', () => {
        const update = { version: '0.6.0', notes: 'Faster', requested: false };
        const { rerender } = render(
            <Computers {...props({ computers: [computer({ update })] })} />,
        );
        expect(screen.queryByText('Add a computer')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Remove/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Install/ }),
        ).not.toBeInTheDocument();

        rerender(
            <Computers
                {...props({
                    computers: [
                        computer({ update, can: { run: true, manage: true } }),
                    ],
                    can: { pair: true },
                    pairing: {
                        code: 'K7QM-2XPA',
                        expires_at: new Date().toISOString(),
                    },
                })}
            />,
        );
        expect(screen.getByLabelText('Pairing code')).toHaveTextContent(
            'K7QM-2XPA',
        );
        expect(
            screen.getByRole('button', { name: /Remove/ }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /Install/ }),
        ).toBeInTheDocument();
    });

    test('a refused command says why', () => {
        render(
            <Computers
                {...props({
                    commands: [
                        command({
                            label: 'format_disk',
                            status: 'rejected',
                            reason: '"format_disk" is not on this computer\'s allowlist',
                            can_confirm: false,
                            can_cancel: false,
                        }),
                    ],
                })}
            />,
        );
        expect(screen.getByText('Refused by the computer')).toBeInTheDocument();
        expect(
            screen.getByText(/not on this computer's allowlist/),
        ).toBeInTheDocument();
    });
});
