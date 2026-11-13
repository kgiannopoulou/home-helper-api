import { router } from '@inertiajs/react';
import { Power } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { store as act } from '@/routes/smart-home/devices/actions';
import type { Device } from '@/types/smart-home';

export type DeviceAction =
    | 'turn_on'
    | 'turn_off'
    | 'toggle'
    | 'set_brightness'
    | 'lock'
    | 'unlock';

export function send(
    device: Device,
    action: DeviceAction,
    extra: Record<string, number> = {},
    onFinish?: () => void,
) {
    router.post(
        act.url(device.id),
        { action, ...extra },
        { preserveScroll: true, preserveState: true, onFinish },
    );
}

/** On/off and, for a dimmable light, brightness. Sensors have no controls. */
export function DeviceControls({ device }: { device: Device }) {
    const [busy, setBusy] = useState(false);
    const [level, setLevel] = useState(device.brightness ?? 100);
    // Follow the real brightness when the page refreshes, unless it's being dragged
    useEffect(() => {
        if (!busy && device.brightness !== null) {
            setLevel(device.brightness);
        }
    }, [device.brightness, busy]);

    if (!device.capabilities.switchable) {
        return null;
    }
    const disabled = busy || !device.available;

    return (
        <div className="flex items-center gap-2">
            <Button
                size="sm"
                variant={device.on ? 'default' : 'outline'}
                disabled={disabled}
                aria-pressed={device.on}
                aria-label={`${device.on ? 'Turn off' : 'Turn on'} ${device.name}`}
                onClick={() => {
                    setBusy(true);
                    send(device, device.on ? 'turn_off' : 'turn_on', {}, () =>
                        setBusy(false),
                    );
                }}
            >
                <Power /> {device.on ? 'On' : 'Off'}
            </Button>
            {device.capabilities.dimmable && (
                <input
                    type="range"
                    min={1}
                    max={100}
                    value={level}
                    disabled={disabled}
                    aria-label={`Brightness of ${device.name}`}
                    className="min-w-0 flex-1 accent-amber-500"
                    onChange={(e) => setLevel(Number(e.target.value))}
                    // One call when the slider is let go, not one per pixel
                    onPointerUp={() => {
                        setBusy(true);
                        send(
                            device,
                            'set_brightness',
                            { brightness: level },
                            () => setBusy(false),
                        );
                    }}
                    onKeyUp={(e) => {
                        if (e.key.startsWith('Arrow')) {
                            send(device, 'set_brightness', {
                                brightness: level,
                            });
                        }
                    }}
                />
            )}
        </div>
    );
}
