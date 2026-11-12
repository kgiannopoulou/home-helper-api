/** The Computers page (app/Http/Controllers/Web/ComputersController.php) */

export type AudioDevice = {
    id: string;
    name: string;
    direction: 'input' | 'output';
    default: boolean;
    state: 'active' | 'unplugged' | 'disabled' | 'unknown';
};

export type AllowedCommand = {
    key: string;
    description: string;
    sensitive: boolean;
    params: { name: string; description: string | null; pattern: string }[];
};

export type HistoryPoint = {
    at: string;
    cpu: number | null;
    memory: number | null;
    disk: number | null;
    temperature: number | null;
};

export type Computer = {
    id: string;
    name: string;
    hostname: string | null;
    platform: string;
    version: string;
    online: boolean;
    last_seen_at: string | null;
    started_at: string | null;
    os: string | null;
    cpu_model: string | null;
    cores: number | null;
    cpu_percent: number | null;
    memory: {
        total_bytes: number;
        used_bytes: number;
        percent: number | null;
    } | null;
    disks: {
        mount: string;
        total_bytes: number;
        free_bytes: number;
        used_percent: number | null;
    }[];
    temperatures: { label: string; celsius: number }[];
    microphones: AudioDevice[];
    speakers: AudioDevice[];
    commands: AllowedCommand[];
    outbox_pending: number;
    update: {
        version: string;
        notes: string | null;
        requested: boolean;
    } | null;
    history: HistoryPoint[];
    can: { run: boolean; manage: boolean };
};

export type ComputerEvent = {
    id: string;
    computer: string;
    type: string;
    severity: 'info' | 'warning' | 'critical';
    message: string;
    at: string;
};

export type CommandStatus =
    | 'awaiting_confirmation'
    | 'queued'
    | 'sent'
    | 'succeeded'
    | 'failed'
    | 'timed_out'
    | 'rejected'
    | 'expired'
    | 'cancelled';

export type ComputerCommand = {
    id: string;
    computer: string;
    label: string;
    status: CommandStatus;
    source: 'dashboard' | 'assistant';
    sensitive: boolean;
    requested_by: string | null;
    confirmed_by: string | null;
    exit_code: number | null;
    output: string | null;
    truncated: boolean;
    reason: string | null;
    duration_ms: number | null;
    created_at: string;
    can_confirm: boolean;
    can_cancel: boolean;
};
