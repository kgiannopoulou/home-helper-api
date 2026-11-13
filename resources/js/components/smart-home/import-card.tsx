import { router } from '@inertiajs/react';
import { Download, RefreshCw } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { groupByRoom, KIND_LABEL } from '@/lib/smart-home';
import { sync } from '@/routes/smart-home';
import { importMethod } from '@/routes/smart-home/devices';
import type { Device } from '@/types/smart-home';

/** The owner picks which discovered devices Home Helper uses (docs/smart-home.md#importing) */
export function ImportCard({ candidates }: { candidates: Device[] }) {
    const [chosen, setChosen] = useState<Set<string>>(new Set());
    const byRoom = useMemo(() => groupByRoom(candidates), [candidates]);

    const toggle = (id: string) =>
        setChosen((s) => {
            const next = new Set(s);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle>Found in Home Assistant</CardTitle>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.post(
                                sync.url(),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <RefreshCw /> Sync now
                    </Button>
                </div>
                <CardDescription>
                    {candidates.length === 0
                        ? 'Nothing new. Devices you add in Home Assistant show up here within a minute.'
                        : 'Choose the lights, switches and sensors Home Helper should use. The rest stays in Home Assistant only.'}
                </CardDescription>
            </CardHeader>
            {candidates.length > 0 && (
                <CardContent className="space-y-4">
                    {byRoom.map(([room, devices]) => (
                        <fieldset key={room} className="space-y-1">
                            <legend className="text-sm font-medium">
                                {room}
                            </legend>
                            {devices.map((d) => (
                                <label
                                    key={d.id}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        checked={chosen.has(d.id)}
                                        onChange={() => toggle(d.id)}
                                        aria-label={`Import ${d.name}`}
                                    />
                                    <span>{d.name}</span>
                                    <Badge variant="outline">
                                        {KIND_LABEL[d.kind]}
                                    </Badge>
                                    <span className="text-muted-foreground">
                                        {d.state_label}
                                    </span>
                                </label>
                            ))}
                        </fieldset>
                    ))}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            disabled={chosen.size === 0}
                            onClick={() =>
                                router.post(
                                    importMethod.url(),
                                    { devices: [...chosen] },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setChosen(new Set()),
                                    },
                                )
                            }
                        >
                            <Download /> Import {chosen.size || ''}
                        </Button>
                        <Button
                            variant="ghost"
                            onClick={() =>
                                setChosen(new Set(candidates.map((d) => d.id)))
                            }
                        >
                            Select all
                        </Button>
                    </div>
                </CardContent>
            )}
        </Card>
    );
}
