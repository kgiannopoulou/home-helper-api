import { DoorClosed, DoorOpen, PanelTop, TriangleAlert } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ago } from '@/lib/computers';
import { cn } from '@/lib/utils';
import type { HomeEvent, Opening } from '@/types/smart-home';

/** Doors and windows: open now and since when, how often today */
export function OpeningsCard({ openings }: { openings: Opening[] }) {
    if (openings.length === 0) {
        return null;
    }
    const open = openings.filter((o) => o.open).length;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Doors and windows</CardTitle>
                <CardDescription>
                    {open === 0
                        ? 'Everything is closed.'
                        : `${open} open right now.`}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="divide-y text-sm">
                    {openings.map((o) => (
                        <li
                            key={o.id}
                            className="flex items-center gap-3 py-2"
                            data-testid={`opening-${o.name}`}
                        >
                            <span
                                className={cn(
                                    'text-muted-foreground',
                                    o.open && 'text-amber-600',
                                )}
                            >
                                {!o.available ? (
                                    <TriangleAlert className="size-4" />
                                ) : o.kind === 'window' ? (
                                    <PanelTop className="size-4" />
                                ) : o.open ? (
                                    <DoorOpen className="size-4" />
                                ) : (
                                    <DoorClosed className="size-4" />
                                )}
                            </span>
                            <span className="flex-1">
                                <span className="font-medium">{o.name}</span>
                                {o.room && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {o.room}
                                    </span>
                                )}
                            </span>
                            <span className="text-muted-foreground tabular-nums">
                                {o.openings_today}× today
                            </span>
                            <Badge variant={o.open ? 'destructive' : 'outline'}>
                                {!o.available
                                    ? 'Not answering'
                                    : o.open
                                      ? `Open · ${ago(o.since)}`
                                      : 'Closed'}
                            </Badge>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

export function EventsCard({ events }: { events: HomeEvent[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>What happened</CardTitle>
                <CardDescription>
                    Doors, windows, motion and warnings, newest first.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {events.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing yet.
                    </p>
                ) : (
                    <ul className="space-y-1 text-sm">
                        {events.map((e) => (
                            <li key={e.id} className="flex items-start gap-2">
                                <Badge
                                    variant={
                                        e.severity === 'info'
                                            ? 'secondary'
                                            : 'destructive'
                                    }
                                    className="shrink-0"
                                >
                                    {e.type}
                                </Badge>
                                <span>
                                    {e.message}
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {ago(e.at)}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
