import { DoorOpen, Droplets, Lightbulb, Thermometer } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { listNames } from '@/lib/smart-home';
import { cn } from '@/lib/utils';
import type { HomeSummary } from '@/types/smart-home';

function Tile({
    icon,
    label,
    value,
    note,
    warn = false,
}: {
    icon: ReactNode;
    label: string;
    value: string;
    note: string;
    warn?: boolean;
}) {
    return (
        <Card className="gap-1 py-4">
            <CardContent className="space-y-1 px-4">
                <div className="flex items-center gap-1 text-xs text-muted-foreground">
                    {icon} {label}
                </div>
                <div
                    className={cn(
                        'text-xl font-semibold tabular-nums',
                        warn && 'text-amber-600 dark:text-amber-400',
                    )}
                >
                    {value}
                </div>
                <div className="truncate text-xs text-muted-foreground">
                    {note}
                </div>
            </CardContent>
        </Card>
    );
}

/** The house at a glance (App\SmartHome\HomeSummary) */
export function SummaryTiles({ summary }: { summary: HomeSummary }) {
    const open = summary.open.length;

    return (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Tile
                icon={<Lightbulb className="size-3.5" />}
                label="Lights"
                value={`${summary.lights_on} on`}
                note={`of ${summary.lights}`}
            />
            <Tile
                icon={<DoorOpen className="size-3.5" />}
                label="Doors and windows"
                value={open ? `${open} open` : 'All closed'}
                note={
                    open
                        ? listNames(summary.open)
                        : summary.unlocked.length
                          ? `${listNames(summary.unlocked)} unlocked`
                          : 'and locked'
                }
                warn={open > 0 || summary.unlocked.length > 0}
            />
            <Tile
                icon={<Thermometer className="size-3.5" />}
                label="Temperature"
                value={
                    summary.temperature
                        ? summary.temperature.min === summary.temperature.max
                            ? `${summary.temperature.min} °C`
                            : `${summary.temperature.min}–${summary.temperature.max} °C`
                        : '–'
                }
                note="across the rooms"
            />
            <Tile
                icon={<Droplets className="size-3.5" />}
                label="Humidity"
                value={summary.humidity ? `${summary.humidity.max}%` : '–'}
                note={
                    summary.humidity?.room
                        ? `highest: ${summary.humidity.room}`
                        : 'no sensor'
                }
                warn={(summary.humidity?.max ?? 0) >= 65}
            />
        </div>
    );
}
