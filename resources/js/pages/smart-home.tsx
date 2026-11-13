import { Head, router } from '@inertiajs/react';
import { X } from 'lucide-react';
import Heading from '@/components/heading';
import { ConnectionCard } from '@/components/smart-home/connection-card';
import { ImportCard } from '@/components/smart-home/import-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { groupByRoom, KIND_LABEL } from '@/lib/smart-home';
import { smartHome } from '@/routes';
import { destroy as removeDevice } from '@/routes/smart-home/devices';
import type {
    Device,
    Room,
    SmartHomeConnection,
    WebhookSecret,
} from '@/types/smart-home';

type Props = {
    connection: SmartHomeConnection | null;
    webhook: WebhookSecret | null;
    devices: Device[];
    candidates: Device[];
    rooms: Room[];
    can: { manage: boolean; control: boolean };
};

export default function SmartHome({
    connection,
    webhook,
    devices,
    candidates,
    can,
}: Props) {
    return (
        <>
            <Head title="Smart home" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Smart home"
                    description="Lights, plugs and sensors, through the household's Home Assistant."
                />
                <ConnectionCard
                    connection={connection}
                    webhook={webhook}
                    canManage={can.manage}
                />

                {connection && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Devices</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {devices.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    No devices imported yet.
                                </p>
                            )}
                            {groupByRoom(devices).map(([room, list]) => (
                                <section key={room} className="space-y-1">
                                    <h3 className="text-sm font-medium">
                                        {room}
                                    </h3>
                                    <ul className="space-y-1 text-sm">
                                        {list.map((d) => (
                                            <li
                                                key={d.id}
                                                className="flex items-center gap-2"
                                            >
                                                <span>{d.name}</span>
                                                <Badge variant="outline">
                                                    {KIND_LABEL[d.kind]}
                                                </Badge>
                                                <span className="text-muted-foreground">
                                                    {d.state_label}
                                                </span>
                                                {can.manage && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label={`Stop using ${d.name}`}
                                                        onClick={() =>
                                                            router.delete(
                                                                removeDevice.url(
                                                                    d.id,
                                                                ),
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <X />
                                                    </Button>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {connection && can.manage && (
                    <ImportCard candidates={candidates} />
                )}
            </div>
        </>
    );
}

SmartHome.layout = {
    breadcrumbs: [{ title: 'Smart home', href: smartHome() }],
};
