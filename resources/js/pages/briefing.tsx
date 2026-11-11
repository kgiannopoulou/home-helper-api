import { Head, router } from '@inertiajs/react';
import { Bot, ThumbsDown, ThumbsUp } from 'lucide-react';
import { useState } from 'react';
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
import { shortDate } from '@/lib/format';
import { briefing as briefingRoute } from '@/routes';
import { start } from '@/routes/assistant';
import { feedback } from '@/routes/briefing';
import type {
    Briefing as BriefingData,
    HouseholdSummary,
    Recommendation,
    TimelineEntry,
} from '@/types/memory';

type Props = {
    briefing: BriefingData;
    timeline: TimelineEntry[];
    kinds: string[];
    filters: { kind: string | null; q: string };
    summaries: HouseholdSummary[];
};

const KIND: Record<string, string> = {
    expense: 'Spent',
    shopping_trip: 'Shopping',
    chore: 'Chore',
    todo: 'To-do',
    plant_care: 'Plants',
    event: 'Calendar',
    admin: 'Bills & admin',
    note: 'Note',
    fact: 'Remembered',
    assistant: 'Assistant',
};

/** "€12.40": exact money for the briefing */
function exact(amount: number | null, currency: string): string {
    return amount === null
        ? '—'
        : new Intl.NumberFormat('en-GB', { style: 'currency', currency }).format(
              amount,
          );
}

/** The briefing's sections as short lines; empty sections are left out */
export function sections(b: BriefingData): { title: string; lines: string[] }[] {
    const m = (x: number | null) => exact(x, b.currency);
    const all = [
        {
            title: 'Weather',
            lines: b.weather
                ? [
                      `${Math.round(b.weather.minimum_temperature_c)}–${Math.round(b.weather.maximum_temperature_c)} °C, ${b.weather.precipitation_mm} mm of rain`,
                  ]
                : b.weather_note
                  ? [b.weather_note]
                  : [],
        },
        {
            title: 'Calendar',
            lines: [
                ...b.calendar.today.map((e) => `${e.starts.slice(11) || e.starts}: ${e.title}`),
                ...b.calendar.tomorrow.map((e) => `Tomorrow ${e.starts.slice(11)}: ${e.title}`),
            ],
        },
        {
            title: 'Tasks',
            lines: [
                ...b.tasks.todos.map((t) => `${t.title}${t.overdue ? ' (overdue)' : ''}`),
                ...b.tasks.chores_due.map((c) => `${c.room}: ${c.name}${c.yours ? ' (yours)' : ''}`),
                ...b.tasks.chores_overdue.map(
                    (c) => `${c.room}: ${c.name}, ${c.days_overdue} days late${c.yours ? ' (yours)' : ''}`,
                ),
            ],
        },
        {
            title: 'Shopping',
            lines: b.shopping
                ? [
                      `${b.shopping.count} on the list${b.shopping.estimated_total ? `, about ${m(b.shopping.estimated_total)}` : ''}`,
                      ...(b.shopping.today_is_shopping_day ? ['Today is your usual shopping day'] : []),
                      ...(b.shopping.running_out.length
                          ? [`Running out: ${b.shopping.running_out.map((r) => r.name).join(', ')}`]
                          : []),
                      ...(b.kitchen?.expiring.length
                          ? [`Use soon: ${b.kitchen.expiring.map((i) => i.name).join(', ')}`]
                          : []),
                  ]
                : [],
        },
        {
            title: 'Money',
            lines: b.money
                ? [
                      `Yesterday ${m(b.money.spent_yesterday)}`,
                      `This month ${m(b.money.month_to_date)}${b.money.budget ? ` of ${m(b.money.budget)}` : ''}`,
                      ...(b.money.over_budget && b.money.over_budget > 0
                          ? [`At this pace ${m(b.money.over_budget)} over budget`]
                          : []),
                  ]
                : [],
        },
        {
            title: 'Bills this week',
            lines: b.bills.map((x) => `${x.name ?? x.title} ${m(x.amount)}, ${shortDate(x.due_on)}`),
        },
        {
            title: 'Plants',
            lines: b.plants
                ? [
                      ...b.plants.to_water.map((p) =>
                          p.skip_for_rain ? `${p.name}: skip, rain is coming` : `Water ${p.name}`,
                      ),
                      ...b.plants.other_care.map((p) => `${p.name}: ${p.what}`),
                      ...b.plants.weather_warnings.map((p) => `${p.name}: ${p.warning}`),
                  ]
                : [],
        },
        {
            title: 'Home upkeep',
            lines: b.maintenance
                ? [
                      ...b.maintenance.due.map((a) => `${a.title}, ${shortDate(a.due_on)}${a.overdue ? ' (overdue)' : ''}`),
                      ...b.maintenance.supplies_low_or_out.map((s) => `${s.name}: ${s.level}`),
                  ]
                : [],
        },
    ];

    return all.filter((s) => s.lines.length > 0);
}

export default function Briefing({
    briefing,
    timeline,
    kinds,
    filters,
    summaries,
}: Props) {
    const [q, setQ] = useState(filters.q);
    const filter = (next: { kind?: string | null; q?: string }) => {
        const params = { kind: filters.kind, q, ...next };
        router.get(
            briefingRoute.url(),
            Object.fromEntries(Object.entries(params).filter(([, v]) => v)),
            { preserveState: true, preserveScroll: true, only: ['timeline', 'filters'] },
        );
    };
    const vote = (r: Recommendation, verdict: 'useful' | 'dismissed') =>
        router.post(feedback.url(), { key: r.key, verdict }, { preserveScroll: true });

    const days = timeline.reduce<Record<string, TimelineEntry[]>>((acc, e) => {
        (acc[e.date] ??= []).push(e);

        return acc;
    }, {});

    return (
        <>
            <Head title="Today" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title={`Good morning, ${briefing.for}`}
                    description={`${briefing.weekday} ${shortDate(briefing.date)} · ${briefing.headline}`}
                />

                {briefing.recommendations.length > 0 && (
                    <section aria-label="Suggestions" className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {briefing.recommendations.map((r) => (
                            <Card key={r.key} className="gap-3 py-4">
                                <CardHeader className="px-4">
                                    <CardTitle className="text-base">{r.title}</CardTitle>
                                    <CardDescription>
                                        {r.reason.charAt(0).toUpperCase() + r.reason.slice(1)}.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-wrap gap-2 px-4">
                                    {r.ask && (
                                        <Button
                                            size="sm"
                                            onClick={() => router.post(start.url(), { message: r.ask })}
                                        >
                                            <Bot /> Ask the assistant
                                        </Button>
                                    )}
                                    <Button size="sm" variant="ghost" aria-label="Useful" onClick={() => vote(r, 'useful')}>
                                        <ThumbsUp />
                                    </Button>
                                    <Button size="sm" variant="ghost" aria-label="Not now" onClick={() => vote(r, 'dismissed')}>
                                        <ThumbsDown /> Not now
                                    </Button>
                                </CardContent>
                            </Card>
                        ))}
                    </section>
                )}

                <section aria-label="Briefing" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {sections(briefing).map((s) => (
                        <Card key={s.title} className="gap-2 py-4">
                            <CardHeader className="px-4">
                                <CardTitle className="text-sm">{s.title}</CardTitle>
                            </CardHeader>
                            <CardContent className="px-4">
                                <ul className="flex flex-col gap-1 text-sm">
                                    {s.lines.map((l) => (
                                        <li key={l}>{l}</li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    ))}
                </section>
                {briefing.unavailable.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        Not available right now: {briefing.unavailable.join(', ')}.
                    </p>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>What happened</CardTitle>
                            <CardDescription>The last two weeks, from every part of the household.</CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="flex flex-wrap gap-2">
                                <select
                                    aria-label="Kind"
                                    className="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                                    value={filters.kind ?? ''}
                                    onChange={(e) => filter({ kind: e.target.value || null })}
                                >
                                    <option value="">Everything</option>
                                    {kinds.map((k) => (
                                        <option key={k} value={k}>
                                            {KIND[k] ?? k}
                                        </option>
                                    ))}
                                </select>
                                <form
                                    className="flex flex-1 gap-2"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        filter({ q });
                                    }}
                                >
                                    <Input
                                        aria-label="Search the timeline"
                                        placeholder="Monstera, oven, Lidl…"
                                        value={q}
                                        onChange={(e) => setQ(e.target.value)}
                                    />
                                    <Button type="submit" variant="outline">
                                        Filter
                                    </Button>
                                </form>
                            </div>
                            {timeline.length === 0 && (
                                <p className="text-sm text-muted-foreground">Nothing in these two weeks.</p>
                            )}
                            {Object.entries(days).map(([date, entries]) => (
                                <div key={date}>
                                    <h3 className="mb-1 text-xs font-medium text-muted-foreground uppercase">
                                        {shortDate(date)}
                                    </h3>
                                    <ul className="flex flex-col gap-1">
                                        {entries.map((e) => (
                                            <li key={`${e.kind}-${e.id}`} className="flex items-baseline gap-2 text-sm">
                                                <span className="w-11 shrink-0 text-xs text-muted-foreground tabular-nums">
                                                    {e.time ?? ''}
                                                </span>
                                                <Badge variant="outline" className="shrink-0">
                                                    {KIND[e.kind] ?? e.kind}
                                                </Badge>
                                                <span className="min-w-0 truncate">
                                                    {e.title}
                                                    {e.detail ? ` · ${e.detail}` : ''}
                                                    {e.amount !== null ? ` · ${exact(e.amount, briefing.currency)}` : ''}
                                                </span>
                                                {e.who && (
                                                    <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                                        {e.who}
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Weekly and monthly summaries</CardTitle>
                            <CardDescription>Written by Home Helper at the end of each period, and kept in its memory.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {summaries.length === 0 ? (
                                <p className="text-sm text-muted-foreground">The first one comes on Monday.</p>
                            ) : (
                                <ul className="flex flex-col gap-4">
                                    {summaries.map((s) => (
                                        <li key={s.id}>
                                            <p className="text-sm font-medium">{s.label}</p>
                                            <p className="text-sm text-muted-foreground">{s.text}</p>
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

Briefing.layout = {
    breadcrumbs: [{ title: 'Today', href: briefingRoute() }],
};
