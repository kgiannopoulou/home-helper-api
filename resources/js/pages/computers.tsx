import { Form, Head, router, usePoll } from '@inertiajs/react';
import {
    Check,
    Cpu,
    Download,
    KeyRound,
    Mic,
    Play,
    Speaker,
    Thermometer,
    Trash2,
    X,
} from 'lucide-react';
import { useState } from 'react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    axis,
    ChartCard,
    grid,
    tooltipStyle,
} from '@/components/charts/chart-card';
import Heading from '@/components/heading';
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
import {
    ago,
    bytes,
    eventTone,
    finished,
    level,
    paramError,
    STATUS_LABEL,
} from '@/lib/computers';
import { cn } from '@/lib/utils';
import { computers as computersRoute } from '@/routes';
import { pair, revoke, update } from '@/routes/computers';
import {
    cancel,
    confirm,
    store as runCommand,
} from '@/routes/computers/commands';
import type {
    AllowedCommand,
    Computer,
    ComputerCommand,
    ComputerEvent,
} from '@/types/computers';

type Props = {
    computers: Computer[];
    events: ComputerEvent[];
    commands: ComputerCommand[];
    can: { pair: boolean };
    pairing: { code: string; expires_at: string } | null;
    heartbeat_seconds: number;
};

/** Commands finish within a heartbeat or two: keep the page close behind */
const REFRESH_MS = 10_000;

const TONE = {
    ok: 'text-foreground',
    warn: 'text-amber-600 dark:text-amber-400',
    critical: 'text-destructive',
    unknown: 'text-muted-foreground',
} as const;

function Reading({
    label,
    value,
    tone,
    detail,
}: {
    label: string;
    value: string;
    tone: keyof typeof TONE;
    detail?: string;
}) {
    return (
        <div className="rounded-lg border p-3">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div
                className={cn('text-xl font-semibold tabular-nums', TONE[tone])}
            >
                {value}
            </div>
            {detail && (
                <div className="text-xs text-muted-foreground">{detail}</div>
            )}
        </div>
    );
}

function RunCommand({
    computer,
    command,
}: {
    computer: Computer;
    command: AllowedCommand;
}) {
    const [values, setValues] = useState<Record<string, string>>({});
    const errors = Object.fromEntries(
        command.params.map((p) => [
            p.name,
            paramError(p.pattern, values[p.name] ?? ''),
        ]),
    );
    const ready = Object.values(errors).every((e) => e === null);

    return (
        <Form
            {...runCommand.form(computer.id)}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="flex flex-col gap-2 rounded-lg border p-3"
        >
            {({ processing }) => (
                <>
                    <input type="hidden" name="command" value={command.key} />
                    <div className="flex items-start justify-between gap-2">
                        <div>
                            <div className="font-medium">
                                {command.key.replaceAll('_', ' ')}
                                {command.sensitive && (
                                    <Badge variant="outline" className="ml-2">
                                        <KeyRound /> owner confirms
                                    </Badge>
                                )}
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {command.description}
                            </p>
                        </div>
                        <Button
                            size="sm"
                            type="submit"
                            disabled={processing || !ready}
                            aria-label={`Run ${command.key} on ${computer.name}`}
                        >
                            <Play /> Run
                        </Button>
                    </div>
                    {command.params.map((p) => (
                        <div key={p.name} className="grid gap-1">
                            <Label
                                htmlFor={`${computer.id}-${command.key}-${p.name}`}
                            >
                                {p.name}
                                {p.description && (
                                    <span className="font-normal text-muted-foreground">
                                        {' '}
                                        · {p.description}
                                    </span>
                                )}
                            </Label>
                            <Input
                                id={`${computer.id}-${command.key}-${p.name}`}
                                name={`params[${p.name}]`}
                                value={values[p.name] ?? ''}
                                onChange={(e) =>
                                    setValues((v) => ({
                                        ...v,
                                        [p.name]: e.target.value,
                                    }))
                                }
                                aria-invalid={
                                    (values[p.name] ?? '') !== '' &&
                                    errors[p.name] !== null
                                }
                                autoComplete="off"
                            />
                            {(values[p.name] ?? '') !== '' &&
                                errors[p.name] && (
                                    <p className="text-xs text-destructive">
                                        {errors[p.name]}
                                    </p>
                                )}
                        </div>
                    ))}
                </>
            )}
        </Form>
    );
}

function ComputerCard({ computer }: { computer: Computer }) {
    const fullest = computer.disks.reduce<Computer['disks'][number] | null>(
        (max, d) =>
            max === null || (d.used_percent ?? 0) > (max.used_percent ?? 0)
                ? d
                : max,
        null,
    );
    const hottest = computer.temperatures.reduce<
        Computer['temperatures'][number] | null
    >((max, t) => (max === null || t.celsius > max.celsius ? t : max), null);

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2">
                        <Cpu className="size-5" /> {computer.name}
                        <Badge
                            variant={computer.online ? 'default' : 'secondary'}
                        >
                            {computer.online ? 'Online' : 'Offline'}
                        </Badge>
                    </CardTitle>
                    {computer.can.manage && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                if (
                                    window.confirm(
                                        `Remove ${computer.name}? Its agent stops at the next check-in and needs a new pairing code to come back.`,
                                    )
                                ) {
                                    router.delete(revoke.url(computer.id), {
                                        preserveScroll: true,
                                    });
                                }
                            }}
                        >
                            <Trash2 /> Remove
                        </Button>
                    )}
                </div>
                <CardDescription>
                    {[
                        computer.os,
                        computer.cpu_model,
                        computer.cores && `${computer.cores} cores`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    <br />
                    Agent {computer.version} · last seen{' '}
                    {ago(computer.last_seen_at)}
                    {computer.outbox_pending > 0 &&
                        ` · ${computer.outbox_pending} waiting to send`}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <Reading
                        label="CPU"
                        value={
                            computer.cpu_percent === null
                                ? '–'
                                : `${Math.round(computer.cpu_percent)}%`
                        }
                        tone={level(computer.cpu_percent, 90, 98)}
                    />
                    <Reading
                        label="Memory"
                        value={
                            computer.memory?.percent == null
                                ? '–'
                                : `${Math.round(computer.memory.percent)}%`
                        }
                        tone={level(computer.memory?.percent ?? null, 90, 97)}
                        detail={
                            computer.memory
                                ? `${bytes(computer.memory.used_bytes)} of ${bytes(computer.memory.total_bytes)}`
                                : undefined
                        }
                    />
                    <Reading
                        label={fullest ? `Disk ${fullest.mount}` : 'Disk'}
                        value={
                            fullest ? bytes(fullest.free_bytes) + ' free' : '–'
                        }
                        tone={level(fullest?.used_percent ?? null, 90, 95)}
                        detail={
                            fullest?.used_percent != null
                                ? `${fullest.used_percent}% used`
                                : undefined
                        }
                    />
                    <Reading
                        label="Temperature"
                        value={
                            hottest ? `${Math.round(hottest.celsius)} °C` : '–'
                        }
                        tone={level(hottest?.celsius ?? null, 85, 95)}
                        detail={hottest ? hottest.label : 'no sensor visible'}
                    />
                </div>

                {computer.update && computer.can.manage && (
                    <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm">
                        <span>
                            Version {computer.update.version} is available
                            {computer.update.notes &&
                                `: ${computer.update.notes}`}
                        </span>
                        {computer.update.requested ? (
                            <Badge variant="outline">
                                Installing at the next check-in
                            </Badge>
                        ) : (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        update.url(computer.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Download /> Install
                            </Button>
                        )}
                    </div>
                )}

                <div className="grid gap-3 sm:grid-cols-2">
                    {(
                        [
                            ['Microphones', Mic, computer.microphones],
                            ['Speakers', Speaker, computer.speakers],
                        ] as const
                    ).map(([title, Icon, devices]) => (
                        <div key={title}>
                            <h3 className="mb-1 flex items-center gap-1 text-sm font-medium">
                                <Icon className="size-4" /> {title}
                            </h3>
                            {devices.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    None found
                                </p>
                            ) : (
                                <ul className="space-y-0.5 text-sm">
                                    {devices.map((d) => (
                                        <li
                                            key={d.id}
                                            className={cn(
                                                d.state !== 'active' &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            {d.name}
                                            {d.default && ' · default'}
                                            {d.state !== 'active' &&
                                                ` · ${d.state}`}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    ))}
                </div>

                {computer.can.run && computer.commands.length > 0 && (
                    <div className="space-y-2">
                        <h3 className="text-sm font-medium">
                            Commands this computer allows
                        </h3>
                        {computer.commands.map((c) => (
                            <RunCommand
                                key={c.key}
                                computer={computer}
                                command={c}
                            />
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function History({ computer }: { computer: Computer }) {
    const sparse = computer.history.length < 30;
    if (computer.history.length < 2) {
        return null;
    }
    const time = (iso: string) =>
        new Date(iso).toLocaleTimeString('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
        });

    return (
        <ChartCard
            title={`${computer.name}: the last 24 hours`}
            description="The highest reading in each 15 minutes"
            rows={computer.history}
            rowKey={(p) => p.at}
            columns={[
                { label: 'Time', value: (p) => time(p.at) },
                { label: 'CPU %', value: (p) => p.cpu ?? '–', numeric: true },
                {
                    label: 'Memory %',
                    value: (p) => p.memory ?? '–',
                    numeric: true,
                },
                { label: 'Disk %', value: (p) => p.disk ?? '–', numeric: true },
                {
                    label: '°C',
                    value: (p) => p.temperature ?? '–',
                    numeric: true,
                },
            ]}
        >
            <div className="h-48">
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart
                        data={computer.history}
                        margin={{ top: 8, right: 8, bottom: 0, left: 0 }}
                    >
                        <CartesianGrid {...grid} />
                        <XAxis dataKey="at" tickFormatter={time} {...axis} />
                        <YAxis
                            domain={[0, 100]}
                            width={32}
                            {...axis}
                            axisLine={false}
                        />
                        <Tooltip
                            {...tooltipStyle}
                            labelFormatter={(v) => time(String(v))}
                        />
                        <Line
                            dataKey="cpu"
                            name="CPU %"
                            stroke="var(--series-1)"
                            dot={sparse}
                            strokeWidth={2}
                            isAnimationActive={false}
                        />
                        <Line
                            dataKey="memory"
                            name="Memory %"
                            stroke="var(--series-2)"
                            dot={sparse}
                            strokeWidth={2}
                            isAnimationActive={false}
                        />
                        <Line
                            dataKey="disk"
                            name="Disk %"
                            stroke="var(--series-3)"
                            dot={sparse}
                            strokeWidth={2}
                            isAnimationActive={false}
                        />
                        <Line
                            dataKey="temperature"
                            name="°C"
                            stroke="var(--series-4)"
                            dot={sparse}
                            strokeWidth={2}
                            isAnimationActive={false}
                        />
                    </LineChart>
                </ResponsiveContainer>
            </div>
        </ChartCard>
    );
}

export default function Computers({
    computers,
    events,
    commands,
    can,
    pairing,
    heartbeat_seconds,
}: Props) {
    usePoll(REFRESH_MS);

    return (
        <>
            <Head title="Computers" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Computers"
                    description={`The household's computers running the Home Helper agent. They check in every ${heartbeat_seconds} seconds and can only run the commands their own configuration allows.`}
                />

                {can.pair && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Add a computer</CardTitle>
                            <CardDescription>
                                Install the agent on it, then run{' '}
                                <code>home-helper-agent enroll --code …</code>{' '}
                                with a code from here. A code works once, for 10
                                minutes.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {pairing ? (
                                <div className="flex flex-wrap items-center gap-3">
                                    <code
                                        className="rounded-md border px-3 py-2 text-2xl font-semibold tracking-widest"
                                        aria-label="Pairing code"
                                    >
                                        {pairing.code}
                                    </code>
                                    <span className="text-sm text-muted-foreground">
                                        Valid until{' '}
                                        {new Date(
                                            pairing.expires_at,
                                        ).toLocaleTimeString('en-GB', {
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        })}
                                        . It won't be shown again.
                                    </span>
                                </div>
                            ) : (
                                <Form
                                    {...pair.form()}
                                    options={{ preserveScroll: true }}
                                    className="flex flex-wrap items-end gap-2"
                                >
                                    {({ processing }) => (
                                        <>
                                            <div className="grid gap-1">
                                                <Label htmlFor="pair-name">
                                                    Name (optional)
                                                </Label>
                                                <Input
                                                    id="pair-name"
                                                    name="name"
                                                    placeholder="Living-room mini PC"
                                                />
                                            </div>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                <KeyRound /> Create a pairing
                                                code
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            )}
                        </CardContent>
                    </Card>
                )}

                {computers.length === 0 ? (
                    <p className="text-muted-foreground">No computers yet.</p>
                ) : (
                    <div className="grid gap-4 xl:grid-cols-2">
                        {computers.map((c) => (
                            <ComputerCard key={c.id} computer={c} />
                        ))}
                    </div>
                )}

                {computers.map((c) => (
                    <History key={c.id} computer={c} />
                ))}

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Commands</CardTitle>
                            <CardDescription>
                                Who asked, and what came back. Sensitive ones
                                wait for the owner.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {commands.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    None yet.
                                </p>
                            ) : (
                                <ul className="space-y-3">
                                    {commands.map((c) => (
                                        <li
                                            key={c.id}
                                            className="rounded-lg border p-3 text-sm"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <span className="font-medium">
                                                    {c.label} · {c.computer}
                                                </span>
                                                <Badge
                                                    variant={
                                                        c.status === 'succeeded'
                                                            ? 'default'
                                                            : finished(c.status)
                                                              ? 'destructive'
                                                              : 'outline'
                                                    }
                                                >
                                                    {STATUS_LABEL[c.status]}
                                                </Badge>
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {c.source === 'assistant'
                                                    ? 'Asked through the assistant by '
                                                    : 'Asked by '}
                                                {c.requested_by ?? 'someone'} ·{' '}
                                                {ago(c.created_at)}
                                                {c.confirmed_by &&
                                                    ` · confirmed by ${c.confirmed_by}`}
                                                {c.exit_code !== null &&
                                                    ` · exit ${c.exit_code}`}
                                                {c.duration_ms !== null &&
                                                    ` · ${(c.duration_ms / 1000).toFixed(1)} s`}
                                            </div>
                                            {c.reason && (
                                                <p className="mt-1 text-xs">
                                                    {c.reason}
                                                </p>
                                            )}
                                            {c.output && (
                                                <pre className="mt-2 max-h-40 overflow-auto rounded bg-muted p-2 text-xs whitespace-pre-wrap">
                                                    {c.output}
                                                    {c.truncated && '\n…(cut)'}
                                                </pre>
                                            )}
                                            {(c.can_confirm ||
                                                c.can_cancel) && (
                                                <div className="mt-2 flex gap-2">
                                                    {c.can_confirm && (
                                                        <Button
                                                            size="sm"
                                                            onClick={() =>
                                                                router.post(
                                                                    confirm.url(
                                                                        c.id,
                                                                    ),
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <Check /> Confirm
                                                        </Button>
                                                    )}
                                                    {c.can_cancel && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                router.post(
                                                                    cancel.url(
                                                                        c.id,
                                                                    ),
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <X /> Cancel
                                                        </Button>
                                                    )}
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>What happened</CardTitle>
                            <CardDescription>
                                From the computers, and what the server noticed
                                about them.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {events.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nothing yet.
                                </p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {events.map((e) => (
                                        <li
                                            key={e.id}
                                            className="flex items-start gap-2"
                                        >
                                            <Badge
                                                variant={eventTone(e)}
                                                className="shrink-0"
                                            >
                                                {e.type.startsWith(
                                                    'temperature',
                                                ) ? (
                                                    <Thermometer />
                                                ) : null}
                                                {e.type}
                                            </Badge>
                                            <span>
                                                <span className="font-medium">
                                                    {e.computer}
                                                </span>
                                                : {e.message}
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
                </div>
            </div>
        </>
    );
}

Computers.layout = {
    breadcrumbs: [{ title: 'Computers', href: computersRoute() }],
};
