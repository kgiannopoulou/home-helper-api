import { router } from '@inertiajs/react';
import { BellRing } from 'lucide-react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { update } from '@/routes/smart-home/notifications';
import type { NotifyLevel, NotifySettings } from '@/types/smart-home';

const LEVEL_LABEL: Record<NotifyLevel, string> = {
    always: 'Always',
    night: 'At night',
    never: 'Never',
};

/** Which events reach the phones (App\SmartHome\Notifications) */
export function NotificationsCard({
    notifications,
    canManage,
}: {
    notifications: NotifySettings;
    canManage: boolean;
}) {
    const save = (type: string, level: NotifyLevel) =>
        router.patch(
            update.url(),
            { notify: { [type]: level } },
            { preserveScroll: true },
        );

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <BellRing className="size-5" /> Notifications
                </CardTitle>
                <CardDescription>
                    What reaches the household's phones. "At night" means{' '}
                    {notifications.night.from}–{notifications.night.to}. The
                    same thing is sent at most every 15 minutes.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <dl className="grid gap-2 sm:grid-cols-2">
                    {Object.entries(notifications.labels).map(
                        ([type, label]) => (
                            <div
                                key={type}
                                className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm"
                            >
                                <dt>
                                    <label htmlFor={`notify-${type}`}>
                                        {label}
                                    </label>
                                </dt>
                                <dd>
                                    <select
                                        id={`notify-${type}`}
                                        className="rounded-md border bg-background px-2 py-1"
                                        value={notifications.settings[type]}
                                        disabled={!canManage}
                                        onChange={(e) =>
                                            save(
                                                type,
                                                e.target.value as NotifyLevel,
                                            )
                                        }
                                    >
                                        {(
                                            Object.keys(
                                                LEVEL_LABEL,
                                            ) as NotifyLevel[]
                                        ).map((l) => (
                                            <option key={l} value={l}>
                                                {LEVEL_LABEL[l]}
                                            </option>
                                        ))}
                                    </select>
                                </dd>
                            </div>
                        ),
                    )}
                </dl>
            </CardContent>
        </Card>
    );
}
