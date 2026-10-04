import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { weekdayDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import { planner as plannerRoute } from '@/routes';
import { apply } from '@/routes/planner';
import type { PlanItem, WeekPlan } from '@/types/home';

const KINDS = {
    chore: { emoji: '🧹', label: 'Chores' },
    meal: { emoji: '🍳', label: 'Meals' },
    errand: { emoji: '📌', label: 'Errands' },
} as const;

type Kind = keyof typeof KINDS;

/**
 * Next week's chores, meals and errands, placed in free time by the server
 * (app/Planning/WeekPlanner.php). Tick what you want and Apply: the ticked items
 * go into the shared calendar in one transaction, all or none.
 */
export default function Planner({ plan }: { plan: WeekPlan }) {
    // Everything not in the calendar yet starts ticked
    const open = plan.items.filter((i) => !i.applied).map((i) => i.key);
    const form = useForm({ week_start: plan.week_start, keys: open });
    const [shown, setShown] = useState<Record<Kind, boolean>>({
        chore: true,
        meal: true,
        errand: true,
    });

    const ticked = new Set(form.data.keys);
    const visible = plan.items.filter((i) => shown[i.kind]);
    const toggle = (key: string, on: boolean) =>
        form.setData(
            'keys',
            on
                ? [...form.data.keys, key]
                : form.data.keys.filter((k) => k !== key),
        );
    const setAll = (on: boolean) => form.setData('keys', on ? open : []);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(apply.url(), {
            preserveScroll: true,
            // The new plan marks what's now in the calendar: tick what's left
            onSuccess: (page) =>
                form.setData(
                    'keys',
                    (page.props.plan as WeekPlan).items
                        .filter((i) => !i.applied)
                        .map((i) => i.key),
                ),
        });
    };

    const minutes = plan.items
        .filter((i) => ticked.has(i.key))
        .reduce((s, i) => s + i.minutes, 0);

    return (
        <>
            <Head title="Week planner" />
            <form onSubmit={submit} className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        title={`Week of ${weekdayDate(plan.week_start)}`}
                        description="Chores, meals and errands, fitted around your calendar. Tick what you want and add it to the shared calendar."
                    />
                    <div className="mb-8 flex flex-wrap items-center gap-2">
                        <fieldset className="flex gap-1 rounded-lg bg-muted p-1 text-sm">
                            <legend className="sr-only">Show</legend>
                            {(Object.keys(KINDS) as Kind[]).map((k) => (
                                <button
                                    key={k}
                                    type="button"
                                    aria-pressed={shown[k]}
                                    onClick={() =>
                                        setShown({ ...shown, [k]: !shown[k] })
                                    }
                                    className={cn(
                                        'rounded-md px-3 py-1',
                                        shown[k]
                                            ? 'bg-background font-medium shadow-sm'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {KINDS[k].emoji} {KINDS[k].label}
                                </button>
                            ))}
                        </fieldset>
                    </div>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3 text-sm">
                    <p>
                        <strong>{form.data.keys.length}</strong> of{' '}
                        {plan.items.length} ticked ·{' '}
                        {Math.round(minutes / 6) / 10} hours
                    </p>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setAll(true)}
                        >
                            Tick all
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setAll(false)}
                        >
                            Untick all
                        </Button>
                        <Button
                            type="submit"
                            size="sm"
                            disabled={
                                form.processing || form.data.keys.length === 0
                            }
                        >
                            {form.processing
                                ? 'Adding…'
                                : `Apply ${form.data.keys.length}`}
                        </Button>
                    </div>
                    <InputError message={form.errors.keys} className="w-full" />
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {plan.days.map((day) => {
                        const items = visible.filter(
                            (i) => i.date === day.date,
                        );

                        return (
                            <Card key={day.date} className="gap-3 py-4">
                                <CardHeader className="px-4">
                                    <CardTitle className="text-base">
                                        {weekdayDate(day.date)}
                                    </CardTitle>
                                    <p className="text-xs text-muted-foreground">
                                        {day.free.length
                                            ? `Free ${day.free.map((f) => `${f.from}–${f.to}`).join(', ')}`
                                            : 'No free time'}
                                    </p>
                                </CardHeader>
                                <CardContent className="space-y-2 px-4">
                                    {day.busy.map((b) => (
                                        <p
                                            key={`${b.title}${b.from}`}
                                            className="rounded-md bg-muted px-2 py-1 text-xs text-muted-foreground"
                                        >
                                            📅{' '}
                                            {b.all_day
                                                ? 'All day'
                                                : `${b.from}–${b.to}`}{' '}
                                            · {b.title}
                                        </p>
                                    ))}
                                    {items.map((item) => (
                                        <PlanRow
                                            key={item.key}
                                            item={item}
                                            checked={ticked.has(item.key)}
                                            onChange={(on) =>
                                                toggle(item.key, on)
                                            }
                                        />
                                    ))}
                                    {items.length === 0 &&
                                        day.busy.length === 0 && (
                                            <p className="text-xs text-muted-foreground">
                                                Nothing planned.
                                            </p>
                                        )}
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {plan.unplaced.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Didn't fit this week
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="list-disc pl-5 text-sm text-muted-foreground">
                                {plan.unplaced.map((i) => (
                                    <li key={i.key}>
                                        {KINDS[i.kind].emoji} {i.title} (
                                        {i.minutes} min, longer than any free
                                        time)
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}
            </form>
        </>
    );
}

function PlanRow({
    item,
    checked,
    onChange,
}: {
    item: PlanItem;
    checked: boolean;
    onChange: (on: boolean) => void;
}) {
    const id = `plan-${item.key}`;

    return (
        <div
            className={cn(
                'flex items-start gap-2 rounded-md border px-2 py-1.5',
                !checked && !item.applied && 'opacity-60',
                item.applied && 'bg-muted/50',
            )}
        >
            <Checkbox
                id={id}
                checked={item.applied || checked}
                disabled={item.applied}
                onCheckedChange={(v) => onChange(v === true)}
                className="mt-0.5"
            />
            <label
                htmlFor={id}
                className="flex-1 cursor-pointer text-sm leading-snug"
            >
                <span className="text-muted-foreground tabular-nums">
                    {item.start}
                </span>{' '}
                <span aria-hidden="true">{KINDS[item.kind].emoji}</span>{' '}
                <span className="font-medium">{item.title}</span>
                <span className="block text-xs text-muted-foreground">
                    {item.minutes} min{item.detail ? ` · ${item.detail}` : ''}
                    {item.moved && item.wanted
                        ? ` · moved from ${weekdayDate(item.wanted)}`
                        : ''}
                    {item.applied ? ' · ✓ in the calendar' : ''}
                </span>
            </label>
        </div>
    );
}

Planner.layout = {
    breadcrumbs: [{ title: 'Week planner', href: plannerRoute() }],
};
