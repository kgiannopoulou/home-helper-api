import { Head, router, usePage, usePoll } from '@inertiajs/react';
import { X } from 'lucide-react';
import Heading from '@/components/heading';
import AlertError from '@/components/alert-error';
import { AutomationsCard } from '@/components/smart-home/automations-card';
import { ClimateChart, ComfortCard } from '@/components/smart-home/climate';
import { ConnectionCard } from '@/components/smart-home/connection-card';
import { DeviceControls } from '@/components/smart-home/device-controls';
import { DeviceTile } from '@/components/smart-home/device-tile';
import { ImportCard } from '@/components/smart-home/import-card';
import { NotificationsCard } from '@/components/smart-home/notifications-card';
import { EventsCard, OpeningsCard } from '@/components/smart-home/openings';
import { SummaryTiles } from '@/components/smart-home/summary-tiles';
import { WorkflowTemplates } from '@/components/smart-home/workflow-templates';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { groupByRoom } from '@/lib/smart-home';
import { smartHome } from '@/routes';
import { destroy as removeDevice } from '@/routes/smart-home/devices';
import type {
    Automations,
    Climate,
    Device,
    HomeEvent,
    HomeSummary,
    NotifySettings,
    Opening,
    RoomComfort,
    Room,
    SmartHomeConnection,
    WebhookSecret,
} from '@/types/smart-home';

type Props = {
    connection: SmartHomeConnection | null;
    webhook: WebhookSecret | null;
    summary: HomeSummary | null;
    devices: Device[];
    comfort: RoomComfort[];
    openings: Opening[];
    events: HomeEvent[];
    notifications: NotifySettings;
    automations: Automations;
    climate: Climate;
    candidates: Device[];
    rooms: Room[];
    can: { manage: boolean; control: boolean };
};

/**
 * States reach Home Helper within a second (the webhook) or a minute (the
 * sync); the page stays a few seconds behind
 */
const REFRESH_MS = 5_000;

function RoomCard({
    room,
    devices,
    canManage,
    canControl,
}: {
    room: string;
    devices: Device[];
    canManage: boolean;
    canControl: boolean;
}) {
    return (
        <Card className="gap-3" role="region" aria-label={room}>
            <CardHeader>
                <CardTitle className="text-base">{room}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-2 sm:grid-cols-2">
                {devices.map((d) => (
                    <DeviceTile key={d.id} device={d}>
                        {canControl && <DeviceControls device={d} />}
                        {canManage && (
                            <div className="flex justify-end">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-6 px-1 text-xs text-muted-foreground"
                                    aria-label={`Stop using ${d.name}`}
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                `Stop using ${d.name} in Home Helper? It stays in Home Assistant.`,
                                            )
                                        ) {
                                            router.delete(
                                                removeDevice.url(d.id),
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                >
                                    <X /> Remove
                                </Button>
                            </div>
                        )}
                    </DeviceTile>
                ))}
            </CardContent>
        </Card>
    );
}

export default function SmartHome({
    connection,
    webhook,
    summary,
    devices,
    comfort,
    climate,
    openings,
    events,
    notifications,
    automations,
    candidates,
    can,
}: Props) {
    usePoll(REFRESH_MS);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const problem =
        errors?.control ?? errors?.device ?? errors?.action ?? errors?.sync;

    return (
        <>
            <Head title="Smart home" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Smart home"
                    description="Lights, plugs and sensors, through the household's Home Assistant."
                />

                {problem && <AlertError errors={[problem]} />}

                {summary && devices.length > 0 && (
                    <SummaryTiles summary={summary} />
                )}

                {connection && devices.length === 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>No devices yet</CardTitle>
                            <CardDescription>
                                {can.manage
                                    ? 'Choose devices from the list Home Assistant gave us, below.'
                                    : 'The household owner chooses which devices Home Helper uses.'}
                            </CardDescription>
                        </CardHeader>
                    </Card>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    {groupByRoom(devices).map(([room, list]) => (
                        <RoomCard
                            key={room}
                            room={room}
                            devices={list}
                            canManage={can.manage}
                            canControl={can.control}
                        />
                    ))}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <OpeningsCard openings={openings} />
                    {devices.length > 0 && <EventsCard events={events} />}
                </div>

                <ComfortCard rooms={comfort} />
                <div className="grid gap-4 lg:grid-cols-2">
                    <ClimateChart climate={climate} kind="temperature" />
                    <ClimateChart climate={climate} kind="humidity" />
                </div>

                {connection && devices.length > 0 && (
                    <AutomationsCard
                        automations={automations}
                        canManage={can.manage}
                    >
                        {can.manage && (
                            <WorkflowTemplates
                                templates={automations.templates}
                            />
                        )}
                    </AutomationsCard>
                )}

                {connection && (
                    <NotificationsCard
                        notifications={notifications}
                        canManage={can.manage}
                    />
                )}

                <ConnectionCard
                    connection={connection}
                    webhook={webhook}
                    canManage={can.manage}
                />

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
