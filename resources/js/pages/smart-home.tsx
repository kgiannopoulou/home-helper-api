import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { ConnectionCard } from '@/components/smart-home/connection-card';
import { smartHome } from '@/routes';
import type { SmartHomeConnection, WebhookSecret } from '@/types/smart-home';

type Props = {
    connection: SmartHomeConnection | null;
    webhook: WebhookSecret | null;
    can: { manage: boolean; control: boolean };
};

export default function SmartHome({ connection, webhook, can }: Props) {
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
            </div>
        </>
    );
}

SmartHome.layout = {
    breadcrumbs: [{ title: 'Smart home', href: smartHome() }],
};
