import type { CommandStatus, ComputerEvent } from '@/types/computers';

/** 17 179 869 184 → "16 GB" (powers of 1000, as disks are sold) */
export function bytes(n: number): string {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = n;
    let unit = 0;
    while (value >= 1000 && unit < units.length - 1) {
        value /= 1000;
        unit++;
    }

    return `${value >= 100 || unit === 0 ? Math.round(value) : value.toFixed(1)} ${units[unit]}`;
}

/** "just now", "3 min ago", "2 h ago", "4 days ago" */
export function ago(iso: string | null, now: Date = new Date()): string {
    if (!iso) {
        return 'never';
    }
    const s = Math.max(
        0,
        Math.round((now.getTime() - new Date(iso).getTime()) / 1000),
    );
    if (s < 45) {
        return 'just now';
    }
    if (s < 3600) {
        return `${Math.round(s / 60)} min ago`;
    }
    if (s < 86400) {
        return `${Math.round(s / 3600)} h ago`;
    }
    const days = Math.round(s / 86400);

    return `${days} day${days === 1 ? '' : 's'} ago`;
}

/** A reading's level against the same lines the agent uses for its events */
export function level(
    value: number | null,
    warnAt: number,
    criticalAt: number,
): 'ok' | 'warn' | 'critical' | 'unknown' {
    if (value === null) {
        return 'unknown';
    }
    if (value >= criticalAt) {
        return 'critical';
    }

    return value >= warnAt ? 'warn' : 'ok';
}

export const STATUS_LABEL: Record<CommandStatus, string> = {
    awaiting_confirmation: 'Waiting for the owner',
    queued: 'Queued',
    sent: 'Running',
    succeeded: 'Done',
    failed: 'Failed',
    timed_out: 'Timed out',
    rejected: 'Refused by the computer',
    expired: 'Expired',
    cancelled: 'Cancelled',
};

/** Finished one way or another: no more updates are coming */
export function finished(status: CommandStatus): boolean {
    return !['awaiting_confirmation', 'queued', 'sent'].includes(status);
}

/** Events worth a coloured badge */
export function eventTone(
    e: ComputerEvent,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (e.severity === 'critical') {
        return 'destructive';
    }

    return e.severity === 'warning' ? 'default' : 'outline';
}

/**
 * The values a command's parameters must have, checked here like the server
 * and the agent check them (whole match), so a mistake shows before sending.
 */
export function paramError(pattern: string, value: string): string | null {
    if (value === '') {
        return 'Required';
    }
    if (value.startsWith('-')) {
        return 'Can’t start with “-”';
    }
    try {
        return new RegExp(`^(?:${pattern})$`).test(value)
            ? null
            : `Must match ${pattern}`;
    } catch {
        return null; // the computer checks it anyway
    }
}
