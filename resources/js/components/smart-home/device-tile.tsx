import {
    Activity,
    DoorClosed,
    DoorOpen,
    Droplets,
    Lightbulb,
    LightbulbOff,
    Lock,
    LockOpen,
    PanelTop,
    Plug,
    Thermometer,
    TriangleAlert,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { ago } from '@/lib/computers';
import { reading } from '@/lib/smart-home';
import { cn } from '@/lib/utils';
import type { Device } from '@/types/smart-home';

export function DeviceIcon({ device }: { device: Device }) {
    const cls = 'size-5';
    if (!device.available) {
        return <TriangleAlert className={cls} />;
    }
    switch (device.kind) {
        case 'light':
            return device.on ? (
                <Lightbulb className={cls} />
            ) : (
                <LightbulbOff className={cls} />
            );
        case 'switch':
            return <Plug className={cls} />;
        case 'lock':
            return device.state === 'unlocked' ? (
                <LockOpen className={cls} />
            ) : (
                <Lock className={cls} />
            );
        case 'temperature':
            return <Thermometer className={cls} />;
        case 'humidity':
            return <Droplets className={cls} />;
        case 'door':
            return device.on ? (
                <DoorOpen className={cls} />
            ) : (
                <DoorClosed className={cls} />
            );
        case 'window':
            return <PanelTop className={cls} />;
        default:
            return <Activity className={cls} />;
    }
}

/** Is this the state you'd want to notice? An open door, an unlocked lock, motion */
export function attention(device: Device): boolean {
    return (
        !device.available ||
        (device.capabilities.contact && device.on) ||
        (device.kind === 'lock' && device.state === 'unlocked')
    );
}

/**
 * One device: icon, name, its state in words. `children` holds the controls
 * (components/smart-home/device-controls.tsx), so a sensor and a lamp share
 * the same tile.
 */
export function DeviceTile({
    device,
    children,
}: {
    device: Device;
    children?: ReactNode;
}) {
    const lit = device.available && device.on && device.capabilities.switchable;

    return (
        <div
            className={cn(
                'flex flex-col gap-2 rounded-lg border p-3',
                lit && 'border-amber-400/60 bg-amber-50 dark:bg-amber-950/30',
                attention(device) && 'border-amber-500',
                !device.available && 'opacity-70',
            )}
            data-testid={`device-${device.entity_id}`}
        >
            <div className="flex items-start gap-2">
                <span
                    className={cn(
                        'mt-0.5 text-muted-foreground',
                        lit && 'text-amber-500',
                    )}
                >
                    <DeviceIcon device={device} />
                </span>
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-medium">
                        {device.name}
                    </div>
                    <div
                        className={cn(
                            'text-sm text-muted-foreground',
                            attention(device) &&
                                'text-amber-700 dark:text-amber-400',
                        )}
                    >
                        {device.capabilities.reading
                            ? reading(device.value, device.unit)
                            : device.state_label}
                        {device.capabilities.contact &&
                            device.on &&
                            ` · ${ago(device.last_changed_at)}`}
                    </div>
                </div>
            </div>
            {children}
        </div>
    );
}
