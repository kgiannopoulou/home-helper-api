import { Head } from '@inertiajs/react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    LineChart,
    ReferenceLine,
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
import { number, shortDate } from '@/lib/format';
import { health as healthRoute } from '@/routes';
import type { HealthWeek } from '@/types/home';

const SLEEP_GOAL = 7;
const STEP_GOAL = 8000;

/**
 * Your own sleep, steps and workouts per week. Three charts, not one with two
 * axes: hours, steps and minutes don't share a scale.
 */
export default function Health({ weeks }: { weeks: HealthWeek[] }) {
    const week = (w: HealthWeek) => shortDate(w.week_start);
    const logged = weeks.some((w) => w.nights || w.steps || w.workouts);

    return (
        <>
            <Head title="Health" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Health"
                    description="Your sleep, steps and workouts over the last 8 weeks. Only you can see this page's numbers."
                />

                {!logged && (
                    <p className="text-sm text-muted-foreground">
                        Nothing logged yet. Sleep, steps and workouts appear
                        here once the app syncs them.
                    </p>
                )}

                <div className="grid gap-6 xl:grid-cols-3">
                    <ChartCard
                        title="Sleep"
                        description={`Average hours a night · goal ${SLEEP_GOAL} h`}
                        rows={weeks}
                        rowKey={(w) => w.week_start}
                        columns={[
                            { label: 'Week of', value: week },
                            {
                                label: 'Hours',
                                value: (w) => w.sleep_hours ?? '–',
                                numeric: true,
                            },
                            {
                                label: 'Quality (1–5)',
                                value: (w) => w.sleep_quality ?? '–',
                                numeric: true,
                            },
                            {
                                label: 'Nights',
                                value: (w) => w.nights,
                                numeric: true,
                            },
                        ]}
                    >
                        <div className="h-56">
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart
                                    data={weeks}
                                    margin={{
                                        top: 8,
                                        right: 8,
                                        bottom: 0,
                                        left: 0,
                                    }}
                                >
                                    <CartesianGrid {...grid} />
                                    <XAxis
                                        dataKey="week_start"
                                        tickFormatter={shortDate}
                                        {...axis}
                                    />
                                    <YAxis
                                        domain={[4, 10]}
                                        width={32}
                                        {...axis}
                                        axisLine={false}
                                    />
                                    <ReferenceLine
                                        y={SLEEP_GOAL}
                                        stroke="var(--viz-axis)"
                                        strokeDasharray="4 4"
                                    />
                                    <Tooltip
                                        {...tooltipStyle}
                                        cursor={{ stroke: 'var(--viz-axis)' }}
                                        labelFormatter={(w) =>
                                            `Week of ${shortDate(String(w))}`
                                        }
                                        formatter={(v) => [`${v} h`, 'Sleep']}
                                    />
                                    <Line
                                        dataKey="sleep_hours"
                                        stroke="var(--series-1)"
                                        strokeWidth={2}
                                        dot={{
                                            r: 4,
                                            fill: 'var(--series-1)',
                                            stroke: 'var(--card)',
                                            strokeWidth: 2,
                                        }}
                                        connectNulls={false}
                                        isAnimationActive={false}
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                    </ChartCard>

                    <ChartCard
                        title="Steps"
                        description={`Average a day · goal ${number(STEP_GOAL)}`}
                        rows={weeks}
                        rowKey={(w) => w.week_start}
                        columns={[
                            { label: 'Week of', value: week },
                            {
                                label: 'A day',
                                value: (w) =>
                                    w.steps_per_day === null
                                        ? '–'
                                        : number(w.steps_per_day),
                                numeric: true,
                            },
                            {
                                label: 'In all',
                                value: (w) => number(w.steps),
                                numeric: true,
                            },
                        ]}
                    >
                        <div className="h-56">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart
                                    data={weeks}
                                    margin={{
                                        top: 8,
                                        right: 8,
                                        bottom: 0,
                                        left: 0,
                                    }}
                                >
                                    <CartesianGrid {...grid} />
                                    <XAxis
                                        dataKey="week_start"
                                        tickFormatter={shortDate}
                                        {...axis}
                                    />
                                    <YAxis
                                        tickFormatter={(v: number) =>
                                            `${v / 1000}k`
                                        }
                                        width={32}
                                        {...axis}
                                        axisLine={false}
                                    />
                                    <ReferenceLine
                                        y={STEP_GOAL}
                                        stroke="var(--viz-axis)"
                                        strokeDasharray="4 4"
                                    />
                                    <Tooltip
                                        {...tooltipStyle}
                                        labelFormatter={(w) =>
                                            `Week of ${shortDate(String(w))}`
                                        }
                                        formatter={(v) => [
                                            number(Number(v)),
                                            'Steps a day',
                                        ]}
                                    />
                                    <Bar
                                        dataKey="steps_per_day"
                                        fill="var(--series-1)"
                                        radius={[4, 4, 0, 0]}
                                        maxBarSize={32}
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </ChartCard>

                    <ChartCard
                        title="Workouts"
                        description="Minutes a week"
                        rows={weeks}
                        rowKey={(w) => w.week_start}
                        columns={[
                            { label: 'Week of', value: week },
                            {
                                label: 'Workouts',
                                value: (w) => w.workouts,
                                numeric: true,
                            },
                            {
                                label: 'Minutes',
                                value: (w) => w.workout_minutes,
                                numeric: true,
                            },
                        ]}
                    >
                        <div className="h-56">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart
                                    data={weeks}
                                    margin={{
                                        top: 8,
                                        right: 8,
                                        bottom: 0,
                                        left: 0,
                                    }}
                                >
                                    <CartesianGrid {...grid} />
                                    <XAxis
                                        dataKey="week_start"
                                        tickFormatter={shortDate}
                                        {...axis}
                                    />
                                    <YAxis
                                        width={32}
                                        {...axis}
                                        axisLine={false}
                                    />
                                    <Tooltip
                                        {...tooltipStyle}
                                        labelFormatter={(w) =>
                                            `Week of ${shortDate(String(w))}`
                                        }
                                        formatter={(v, _n, item) => [
                                            `${v} min in ${(item.payload as HealthWeek).workouts}`,
                                            'Workouts',
                                        ]}
                                    />
                                    <Bar
                                        dataKey="workout_minutes"
                                        fill="var(--series-1)"
                                        radius={[4, 4, 0, 0]}
                                        maxBarSize={32}
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </ChartCard>
                </div>
            </div>
        </>
    );
}

Health.layout = {
    breadcrumbs: [{ title: 'Health', href: healthRoute() }],
};
