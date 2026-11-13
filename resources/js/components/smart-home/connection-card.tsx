import { Form, router } from '@inertiajs/react';
import { KeyRound, Plug, RefreshCw, Unplug } from 'lucide-react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ago } from '@/lib/computers';
import { check, destroy, secret, store } from '@/routes/smart-home/connection';
import type {
    ConnectionStatus,
    SmartHomeConnection,
    WebhookSecret,
} from '@/types/smart-home';

export const STATUS_LABEL: Record<ConnectionStatus, string> = {
    connected: 'Connected',
    unreachable: 'Not answering',
    unauthorized: 'Token refused',
    error: 'Problem',
};

function ConnectForm() {
    return (
        <Form
            {...store.form()}
            options={{ preserveScroll: true }}
            resetOnSuccess={['token']}
            className="grid gap-3 sm:max-w-xl"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-1">
                        <Label htmlFor="ha-url">Home Assistant address</Label>
                        <Input
                            id="ha-url"
                            name="url"
                            placeholder="http://192.168.1.20:8123"
                            autoComplete="off"
                            required
                        />
                        <InputError message={errors.url} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ha-token">
                            Long-lived access token
                        </Label>
                        <Input
                            id="ha-token"
                            name="token"
                            type="password"
                            autoComplete="off"
                            required
                        />
                        <p className="text-xs text-muted-foreground">
                            In Home Assistant: your profile → Security →
                            Long-lived access tokens → Create token.
                        </p>
                        <InputError message={errors.token} />
                    </div>
                    <div>
                        <Button type="submit" disabled={processing}>
                            <Plug /> Connect
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

export function WebhookCard({ webhook }: { webhook: WebhookSecret }) {
    return (
        <div className="space-y-2 rounded-lg border border-amber-500/50 p-3 text-sm">
            <p className="font-medium">
                <KeyRound className="mr-1 inline size-4" /> For Home Assistant's{' '}
                <code>secrets.yaml</code> — shown once
            </p>
            <pre className="overflow-x-auto rounded bg-muted p-2 text-xs">
                {`home_helper_events_url: "${webhook.url}"\nhome_helper_events_auth: "Bearer ${webhook.token}"`}
            </pre>
            <p className="text-muted-foreground">
                With <code>packages/home_helper.yaml</code>, Home Assistant then
                tells Home Helper about every change as it happens. Lose it?
                Make a new one; the old one stops working.
            </p>
        </div>
    );
}

export function ConnectionCard({
    connection,
    webhook,
    canManage,
}: {
    connection: SmartHomeConnection | null;
    webhook: WebhookSecret | null;
    canManage: boolean;
}) {
    if (!connection) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Connect Home Assistant</CardTitle>
                    <CardDescription>
                        Home Helper reaches your lights, plugs and sensors
                        through Home Assistant: on a Raspberry Pi at home, or
                        the test home in Docker.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {canManage ? (
                        <ConnectForm />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            The household owner can connect it.
                        </p>
                    )}
                </CardContent>
            </Card>
        );
    }

    const ok = connection.status === 'connected';

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2">
                        {connection.location_name ?? 'Home Assistant'}
                        <Badge variant={ok ? 'default' : 'destructive'}>
                            {STATUS_LABEL[connection.status]}
                        </Badge>
                    </CardTitle>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    check.url(),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <RefreshCw /> Check
                        </Button>
                        {canManage && (
                            <>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            secret.url(),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <KeyRound /> New webhook secret
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                'Disconnect Home Assistant? Home Helper forgets its devices, readings and rules. Home Assistant itself is not changed.',
                                            )
                                        ) {
                                            router.delete(destroy.url());
                                        }
                                    }}
                                >
                                    <Unplug /> Disconnect
                                </Button>
                            </>
                        )}
                    </div>
                </div>
                <CardDescription>
                    {connection.base_url}
                    {connection.ha_version &&
                        ` · Home Assistant ${connection.ha_version}`}
                    <br />
                    Last answered {ago(connection.last_ok_at)} · last change
                    reported {ago(connection.last_event_at)}
                </CardDescription>
            </CardHeader>
            {(connection.status_message || webhook) && (
                <CardContent className="space-y-3">
                    {connection.status_message && (
                        <p className="text-sm text-destructive">
                            {connection.status_message}
                        </p>
                    )}
                    {webhook && <WebhookCard webhook={webhook} />}
                </CardContent>
            )}
        </Card>
    );
}
