import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { budgetState } from '@/lib/charts';
import { money } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Forecast } from '@/types/home';

/**
 * Where the month is heading against the budget (the budget-forecast query):
 * the forecast as the headline, a meter of spent and forecast, and the history
 * the forecast leans on early in the month.
 */
export function BudgetCard({
    forecast: f,
    currency,
}: {
    forecast: Forecast;
    currency: string;
}) {
    const state = budgetState(f);
    const share = f.budget
        ? Math.min(1, (f.forecast ?? f.spent) / f.budget)
        : 0;
    const spentShare = f.budget ? Math.min(1, f.spent / f.budget) : 0;

    const headline = {
        none: `${money(f.spent, currency)} spent this month`,
        early: `${money(f.spent, currency)} spent so far`,
        under: `${money(f.forecast ?? 0, currency)} at this pace`,
        close: `${money(f.forecast ?? 0, currency)} at this pace`,
        over: `${money(f.forecast ?? 0, currency)} at this pace`,
    }[state];

    const note = {
        none: 'No monthly budget set. Add one in the app to see how the month is going.',
        early: 'Too early in the month for a forecast.',
        under: `Under your ${money(f.budget ?? 0, currency)} budget.`,
        close: `Close to your ${money(f.budget ?? 0, currency)} budget.`,
        over: `${money(f.over_budget ?? 0, currency)} over your ${money(f.budget ?? 0, currency)} budget.`,
    }[state];

    return (
        <Card>
            <CardHeader>
                <CardDescription>
                    This month, day {f.day} of {f.days_in_month}
                </CardDescription>
                <CardTitle className="text-3xl font-semibold">
                    {headline}
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                <p
                    className={cn(
                        'text-sm',
                        state === 'over'
                            ? 'font-medium text-[var(--viz-critical)]'
                            : 'text-muted-foreground',
                    )}
                >
                    {state === 'over' && <span aria-hidden="true">⚠️ </span>}
                    {note}
                </p>
                {f.budget !== null && (
                    <div
                        role="meter"
                        aria-label="Forecast against budget"
                        aria-valuemin={0}
                        aria-valuemax={f.budget}
                        aria-valuenow={f.forecast ?? f.spent}
                        className="relative h-3 overflow-hidden rounded-full bg-muted"
                    >
                        {/* Forecast (lighter) behind what's spent so far */}
                        <div
                            className="absolute inset-y-0 left-0 rounded-full opacity-40"
                            style={{
                                width: `${share * 100}%`,
                                background:
                                    state === 'over'
                                        ? 'var(--viz-critical)'
                                        : 'var(--series-1)',
                            }}
                        />
                        <div
                            className="absolute inset-y-0 left-0 rounded-full"
                            style={{
                                width: `${spentShare * 100}%`,
                                background:
                                    state === 'over'
                                        ? 'var(--viz-critical)'
                                        : 'var(--series-1)',
                            }}
                        />
                    </div>
                )}
                <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
                    <Stat term="Spent" value={money(f.spent, currency)} />
                    <Stat
                        term="Of which bills"
                        value={money(f.bills, currency)}
                    />
                    <Stat
                        term="Usual by today"
                        value={
                            f.history.usual_by_this_day === null
                                ? '–'
                                : money(f.history.usual_by_this_day, currency)
                        }
                    />
                    <Stat
                        term="Average month"
                        value={
                            f.history.average_month === null
                                ? '–'
                                : money(f.history.average_month, currency)
                        }
                    />
                </dl>
            </CardContent>
        </Card>
    );
}

function Stat({ term, value }: { term: string; value: string }) {
    return (
        <div>
            <dt className="text-muted-foreground">{term}</dt>
            <dd className="font-medium">{value}</dd>
        </div>
    );
}
