import { Head } from '@inertiajs/react';
import {
    Bar,
    BarChart,
    LabelList,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { ChartCard, tooltipStyle } from '@/components/charts/chart-card';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { shortDate } from '@/lib/format';
import { chores as choresRoute } from '@/routes';
import type { FairShare, OverdueChore, SlippingChore } from '@/types/home';

type Props = {
    overdue: OverdueChore[];
    slipping: SlippingChore[];
    fairShare: FairShare[];
    days: number;
};

const days = (n: number) => `${n} ${n === 1 ? 'day' : 'days'}`;

export default function Chores({
    overdue,
    slipping,
    fairShare,
    days: period,
}: Props) {
    const total = fairShare.reduce((s, m) => s + m.minutes, 0);

    return (
        <>
            <Head title="Chores" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Chores"
                    description="What slipped, and who does how much."
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Overdue</CardTitle>
                            <CardDescription>
                                Past the day they were due, the most overdue
                                first
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {overdue.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nothing overdue. 🎉
                                </p>
                            ) : (
                                <ul className="divide-y divide-border">
                                    {overdue.map((c) => (
                                        <li
                                            key={c.id}
                                            className="flex items-center justify-between gap-4 py-2 text-sm"
                                        >
                                            <div>
                                                <p className="font-medium">
                                                    {c.room}: {c.name}
                                                </p>
                                                <p className="text-muted-foreground">
                                                    every {days(c.every_days)} ·
                                                    last done{' '}
                                                    {shortDate(c.last_done)}
                                                </p>
                                            </div>
                                            <Badge
                                                variant={
                                                    c.days_overdue >
                                                    c.every_days
                                                        ? 'destructive'
                                                        : 'secondary'
                                                }
                                            >
                                                {days(c.days_overdue)} late
                                            </Badge>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Slipping</CardTitle>
                            <CardDescription>
                                Done 30% late (or early) three times running: a
                                frequency that matches what you really do
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {slipping.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Every chore is keeping to its schedule.
                                </p>
                            ) : (
                                <ul className="divide-y divide-border">
                                    {slipping.map((c) => (
                                        <li key={c.id} className="py-2 text-sm">
                                            <p className="font-medium">
                                                {c.room}: {c.name}
                                            </p>
                                            <p className="text-muted-foreground">
                                                Set to every{' '}
                                                {days(c.every_days)}, done every{' '}
                                                {c.gaps.join(', ')} days. Every{' '}
                                                {days(c.suggested_every_days)}{' '}
                                                fits better.
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <ChartCard
                    title="Fair share"
                    description={`Minutes of chores in the last ${period} days. Minutes, not chores: the oven isn't a counter.`}
                    rows={fairShare}
                    rowKey={(m) => String(m.user_id)}
                    columns={[
                        { label: 'Member', value: (m) => m.name },
                        {
                            label: 'Minutes',
                            value: (m) => m.minutes,
                            numeric: true,
                        },
                        {
                            label: 'Chores',
                            value: (m) => m.chores,
                            numeric: true,
                        },
                        {
                            label: 'Share',
                            value: (m) => `${Math.round(m.share * 100)}%`,
                            numeric: true,
                        },
                    ]}
                >
                    {total === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No chores done in the last {period} days.
                        </p>
                    ) : (
                        <div style={{ height: 48 + fairShare.length * 40 }}>
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart
                                    data={fairShare}
                                    layout="vertical"
                                    margin={{
                                        top: 0,
                                        right: 96,
                                        bottom: 0,
                                        left: 0,
                                    }}
                                >
                                    <XAxis
                                        type="number"
                                        hide
                                        domain={[0, 'dataMax']}
                                    />
                                    <YAxis
                                        type="category"
                                        dataKey="name"
                                        width={96}
                                        tickLine={false}
                                        axisLine={false}
                                        tick={{
                                            fill: 'var(--foreground)',
                                            fontSize: 13,
                                        }}
                                    />
                                    <Tooltip
                                        {...tooltipStyle}
                                        formatter={(v, _n, item) => [
                                            `${v} min · ${(item.payload as FairShare).chores} chores`,
                                            'Done',
                                        ]}
                                    />
                                    <Bar
                                        dataKey="minutes"
                                        fill="var(--series-1)"
                                        radius={[0, 4, 4, 0]}
                                        barSize={24}
                                    >
                                        <LabelList
                                            dataKey="share"
                                            position="right"
                                            formatter={(v) =>
                                                `${Math.round(Number(v) * 100)}%`
                                            }
                                            style={{
                                                fill: 'var(--foreground)',
                                                fontSize: 13,
                                            }}
                                        />
                                    </Bar>
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    )}
                </ChartCard>
            </div>
        </>
    );
}

Chores.layout = {
    breadcrumbs: [{ title: 'Chores', href: choresRoute() }],
};
