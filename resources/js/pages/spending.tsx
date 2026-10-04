import { Head, Link, usePage } from '@inertiajs/react';
import {
    Bar,
    BarChart,
    CartesianGrid,
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
import { BudgetCard } from '@/components/budget-card';
import { categoryColor, OTHER, stackWeeks } from '@/lib/charts';
import { label, money, shortDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import { spending as spendingRoute } from '@/routes';
import type {
    CurrentHousehold,
    Forecast,
    WeeklySpendingRow,
} from '@/types/home';

type Props = {
    weeks: number;
    weekStarts: string[];
    spending: WeeklySpendingRow[];
    forecast: Forecast;
};

const RANGES = [4, 8, 12, 26];

const keyLabel = (key: string) => (key === OTHER ? 'Other' : label(key));

export default function Spending({
    weeks,
    weekStarts,
    spending,
    forecast,
}: Props) {
    const { household } = usePage<{ household: CurrentHousehold }>().props;
    const currency = household.currency;
    const { bars, keys } = stackWeeks(spending, weekStarts);

    return (
        <>
            <Head title="Spending" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        title="Spending"
                        description={`${household.name}: what the household spends each week, and where the month is heading.`}
                    />
                    <nav
                        aria-label="Weeks shown"
                        className="mb-8 flex gap-1 rounded-lg bg-muted p-1 text-sm"
                    >
                        {RANGES.map((n) => (
                            <Link
                                key={n}
                                href={spendingRoute({ query: { weeks: n } })}
                                preserveScroll
                                className={cn(
                                    'rounded-md px-3 py-1',
                                    n === weeks
                                        ? 'bg-background font-medium shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                                aria-current={n === weeks ? 'true' : undefined}
                            >
                                {n} weeks
                            </Link>
                        ))}
                    </nav>
                </div>

                <BudgetCard forecast={forecast} currency={currency} />

                <ChartCard
                    title="Spending per week"
                    description="Monday to Sunday, by category"
                    rows={bars}
                    rowKey={(b) => b.week_start}
                    columns={[
                        {
                            label: 'Week of',
                            value: (b) => shortDate(b.week_start),
                        },
                        ...keys.map((k) => ({
                            label: keyLabel(k),
                            value: (b: (typeof bars)[number]) =>
                                money(Number(b[k] ?? 0), currency),
                            numeric: true,
                        })),
                        {
                            label: 'Total',
                            value: (b) => money(b.total, currency),
                            numeric: true,
                        },
                    ]}
                >
                    <ul className="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                        {keys.map((k) => (
                            <li key={k} className="flex items-center gap-1.5">
                                <span
                                    className="size-2.5 rounded-sm"
                                    style={{ background: categoryColor(k) }}
                                />
                                {keyLabel(k)}
                            </li>
                        ))}
                    </ul>
                    <div className="h-72">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart
                                data={bars}
                                margin={{
                                    top: 8,
                                    right: 8,
                                    bottom: 0,
                                    left: 8,
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
                                        money(v, currency)
                                    }
                                    width={64}
                                    {...axis}
                                    axisLine={false}
                                />
                                <Tooltip
                                    {...tooltipStyle}
                                    labelFormatter={(w) =>
                                        `Week of ${shortDate(String(w))}`
                                    }
                                    formatter={(v, name) => [
                                        money(Number(v), currency),
                                        keyLabel(String(name)),
                                    ]}
                                />
                                {keys.map((k) => (
                                    <Bar
                                        key={k}
                                        dataKey={k}
                                        stackId="week"
                                        fill={categoryColor(k)}
                                        // A thin gap between stacked segments
                                        stroke="var(--card)"
                                        strokeWidth={1}
                                        maxBarSize={44}
                                    />
                                ))}
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </ChartCard>
            </div>
        </>
    );
}

Spending.layout = {
    breadcrumbs: [{ title: 'Spending', href: spendingRoute() }],
};
