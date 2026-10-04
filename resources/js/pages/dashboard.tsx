import { Head, Link, usePage } from '@inertiajs/react';
import { BudgetCard } from '@/components/budget-card';
import Heading from '@/components/heading';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { money, weekdayDate } from '@/lib/format';
import { chores, dashboard, planner, spending } from '@/routes';
import type { CurrentHousehold, Forecast, OverdueChore } from '@/types/home';

type Props = {
    forecast: Forecast;
    spentThisWeek: number;
    shoppingDay: {
        weekday: number;
        name: string;
        next_on: string;
        share: number;
    } | null;
    overdue: OverdueChore[];
    toBuy: number;
};

export default function Dashboard({
    forecast,
    spentThisWeek,
    shoppingDay,
    overdue,
    toBuy,
}: Props) {
    const { household } = usePage<{ household: CurrentHousehold }>().props;
    const currency = household.currency;

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title={household.name}
                    description="The same household as on the phone, at a glance."
                />

                <div className="grid gap-4 md:grid-cols-3">
                    <Tile
                        title="This week"
                        value={money(spentThisWeek, currency)}
                        note="spent since Monday"
                        href={spending()}
                    />
                    <Tile
                        title="Shopping"
                        value={
                            shoppingDay ? weekdayDate(shoppingDay.next_on) : '–'
                        }
                        note={`${toBuy} ${toBuy === 1 ? 'thing' : 'things'} on the list${shoppingDay ? `, usually on ${shoppingDay.name}` : ''}`}
                    />
                    <Tile
                        title="Chores"
                        value={
                            overdue.length
                                ? `${overdue.length} overdue`
                                : 'On track'
                        }
                        note={
                            overdue.length
                                ? overdue
                                      .map((c) => c.name)
                                      .slice(0, 3)
                                      .join(', ')
                                : 'nothing past its day'
                        }
                        href={chores()}
                    />
                </div>

                <BudgetCard forecast={forecast} currency={currency} />

                <p className="text-sm text-muted-foreground">
                    Plan next week's chores, meals and errands in the{' '}
                    <Link
                        href={planner()}
                        className="underline underline-offset-4"
                    >
                        week planner
                    </Link>
                    .
                </p>
            </div>
        </>
    );
}

function Tile({
    title,
    value,
    note,
    href,
}: {
    title: string;
    value: string;
    note: string;
    href?: Parameters<typeof Link>[0]['href'];
}) {
    const body = (
        <Card className="h-full gap-2 transition-colors hover:bg-muted/40">
            <CardHeader>
                <CardDescription>{title}</CardDescription>
                <CardTitle className="text-2xl font-semibold">
                    {value}
                </CardTitle>
            </CardHeader>
            <CardContent className="text-sm text-muted-foreground">
                {note}
            </CardContent>
        </Card>
    );

    return href ? <Link href={href}>{body}</Link> : body;
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
