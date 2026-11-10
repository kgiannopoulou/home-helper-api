import { Head, usePoll } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { health as systemHealth } from '@/routes/system';
import type {
    AppFacts,
    CheckStatus,
    FailedJob,
    HealthReport,
    HouseholdJobState,
    OpsAlert,
} from '@/types/health';

type Props = {
    health: HealthReport;
    app: AppFacts;
    alerts: OpsAlert[];
};

/** The checks are cheap, but there's no reason to run them more often than this */
const REFRESH_MS = 15_000;

const CHECK_NAMES: Record<string, string> = {
    database: 'Database',
    redis: 'Redis',
    queues: 'Queues',
    failed_jobs: 'Failed jobs',
    scheduler: 'Scheduler',
    household_jobs: 'Household jobs',
    backups: 'Backups',
    disk: 'Disk',
};

const STATUS_LABEL: Record<CheckStatus, string> = {
    ok: 'OK',
    warn: 'Check',
    fail: 'Failing',
};

const OVERALL: Record<HealthReport['status'], { label: string; note: string }> =
    {
        ok: { label: 'All systems working', note: 'Every check passed.' },
        degraded: {
            label: 'Working, with problems',
            note: 'The app answers, but something below needs a look.',
        },
        down: {
            label: 'Down',
            note: 'The database is unreachable: nothing works.',
        },
    };

/** A coloured dot plus the word, so the status never depends on colour alone */
export function StatusPill({ status }: { status: CheckStatus }) {
    return (
        <span className="inline-flex items-center gap-1.5 text-sm font-medium">
            <span
                aria-hidden
                className={cn(
                    'size-2.5 rounded-full',
                    status === 'ok' && 'bg-emerald-500',
                    status === 'warn' && 'bg-amber-500',
                    status === 'fail' && 'bg-destructive',
                )}
            />
            {STATUS_LABEL[status]}
        </span>
    );
}

const when = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : 'never';

export default function SystemHealth({ health, app, alerts }: Props) {
    usePoll(REFRESH_MS);
    const overall = OVERALL[health.status];
    const jobs = (health.checks.household_jobs?.details.jobs ?? {}) as Record<
        string,
        HouseholdJobState
    >;
    const failed = (health.checks.failed_jobs?.details.latest ??
        []) as FailedJob[];

    return (
        <>
            <Head title="System health" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="System health"
                    description={`Checked ${when(health.checked_at)}, refreshes every ${REFRESH_MS / 1000} seconds.`}
                />

                <Card
                    role="status"
                    className={cn(
                        'border-l-4',
                        health.status === 'ok' && 'border-l-emerald-500',
                        health.status === 'degraded' && 'border-l-amber-500',
                        health.status === 'down' && 'border-l-destructive',
                    )}
                >
                    <CardHeader>
                        <CardTitle>{overall.label}</CardTitle>
                        <CardDescription>{overall.note}</CardDescription>
                    </CardHeader>
                </Card>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {Object.entries(health.checks).map(([name, check]) => (
                        <Card key={name} data-testid={`check-${name}`}>
                            <CardHeader className="gap-1">
                                <div className="flex items-center justify-between gap-2">
                                    <CardTitle className="text-base">
                                        {CHECK_NAMES[name] ?? name}
                                    </CardTitle>
                                    <StatusPill status={check.status} />
                                </div>
                                <CardDescription className="break-words">
                                    {check.summary}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="mt-auto text-xs text-muted-foreground tabular-nums">
                                {check.ms} ms
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Household jobs</CardTitle>
                            <CardDescription>
                                Late when a job hasn&apos;t started in twice its
                                interval
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left text-muted-foreground">
                                    <tr>
                                        <th className="py-1 font-medium">
                                            Job
                                        </th>
                                        <th className="py-1 font-medium">
                                            Last run
                                        </th>
                                        <th className="py-1 text-right font-medium">
                                            Failed (24 h)
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {Object.entries(jobs).map(([job, s]) => (
                                        <tr key={job}>
                                            <td className="py-2">
                                                {job}
                                                {s.late && (
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-2"
                                                    >
                                                        late
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="py-2">
                                                {when(s.last_started_at)}
                                                {s.last_status &&
                                                    ` · ${s.last_status}`}
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                {s.failed_24h}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Recent alerts</CardTitle>
                            <CardDescription>
                                Also in storage/logs/ops-*.log, and emailed to
                                OPS_ALERT_EMAIL
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {alerts.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No alerts.
                                </p>
                            ) : (
                                <ul className="divide-y divide-border text-sm">
                                    {alerts.slice(0, 10).map((a, i) => (
                                        <li
                                            key={`${a.key}-${a.at}-${i}`}
                                            className="py-2"
                                        >
                                            <p className="font-medium">
                                                {a.title}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {when(a.at)} · {a.key}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Failed jobs</CardTitle>
                            <CardDescription>
                                The newest five; php artisan queue:retry
                                &lt;uuid&gt; runs one again
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {failed.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No failed jobs.
                                </p>
                            ) : (
                                <ul className="divide-y divide-border text-sm">
                                    {failed.map((f) => (
                                        <li key={f.uuid} className="py-2">
                                            <p className="font-medium">
                                                {f.job}{' '}
                                                <span className="font-normal text-muted-foreground">
                                                    on {f.queue}
                                                </span>
                                            </p>
                                            <p className="break-words text-muted-foreground">
                                                {when(f.failed_at)} · {f.error}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>This server</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                {(
                                    [
                                        ['Environment', app.environment],
                                        ['Debug', app.debug ? 'on' : 'off'],
                                        ['Version', app.version ?? 'unknown'],
                                        ['PHP', app.php],
                                        ['Laravel', app.laravel],
                                        ['Home time zone', app.timezone],
                                        ['Queue', app.queue],
                                        ['Cache', app.cache],
                                        [
                                            'Config cached',
                                            app.config_cached ? 'yes' : 'no',
                                        ],
                                    ] as const
                                ).map(([label, value]) => (
                                    <div key={label} className="contents">
                                        <dt className="text-muted-foreground">
                                            {label}
                                        </dt>
                                        <dd className="tabular-nums">
                                            {value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

SystemHealth.layout = {
    breadcrumbs: [{ title: 'System health', href: systemHealth() }],
};
