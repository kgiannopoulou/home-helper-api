import { Head, router, useForm, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { shortDate } from '@/lib/format';
import { invite, show, store, switchMethod } from '@/routes/household';
import type {
    CurrentHousehold,
    HouseholdOption,
    Member,
    PendingInvite,
} from '@/types/home';

type Props = {
    members: Member[];
    invites: PendingInvite[];
    canInvite: boolean;
};

export default function Household({ members, invites, canInvite }: Props) {
    const { household, households } = usePage<{
        household: CurrentHousehold | null;
        households: HouseholdOption[];
    }>().props;

    return (
        <>
            <Head title="Household" />
            <div className="flex max-w-3xl flex-col gap-6 p-4 md:p-6">
                <Heading
                    title={household ? household.name : 'Start a household'}
                    description={
                        household
                            ? 'Everyone here shares the shopping list, kitchen, money, chores and planner. Food, sleep and workouts stay personal.'
                            : 'A household shares one shopping list, kitchen, money, chores and planner. Start one, or open an invite link from your email.'
                    }
                />

                {household && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Members</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="divide-y divide-border">
                                {members.map((m) => (
                                    <li
                                        key={m.id}
                                        className="flex items-center justify-between gap-4 py-2 text-sm"
                                    >
                                        <div>
                                            <p className="font-medium">
                                                {m.name}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {m.email}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-3 text-muted-foreground">
                                            {m.joined && (
                                                <span className="hidden sm:inline">
                                                    since {shortDate(m.joined)}
                                                </span>
                                            )}
                                            <Badge
                                                variant={
                                                    m.role === 'owner'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {m.role}
                                            </Badge>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {household && (
                    <Invites invites={invites} canInvite={canInvite} />
                )}

                {households.length > 1 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Switch household</CardTitle>
                            <CardDescription>
                                The dashboard shows one household at a time.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            {households.map((h) => (
                                <Button
                                    key={h.id}
                                    variant={
                                        h.id === household?.id
                                            ? 'default'
                                            : 'outline'
                                    }
                                    size="sm"
                                    disabled={h.id === household?.id}
                                    onClick={() =>
                                        router.post(
                                            switchMethod.url(h.id),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {h.name}
                                </Button>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <NewHousehold first={!household} />
            </div>
        </>
    );
}

function Invites({
    invites,
    canInvite,
}: {
    invites: PendingInvite[];
    canInvite: boolean;
}) {
    const form = useForm({ email: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(invite.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Invites</CardTitle>
                <CardDescription>
                    {canInvite
                        ? 'They get an email with a link that works for 7 days. They open it in the app after signing in with that address.'
                        : 'Only the owner can invite people.'}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {canInvite && (
                    <form
                        onSubmit={submit}
                        className="flex flex-wrap items-start gap-2"
                    >
                        <div className="grid flex-1 gap-1">
                            <Label htmlFor="invite-email" className="sr-only">
                                Email
                            </Label>
                            <Input
                                id="invite-email"
                                type="email"
                                placeholder="name@example.com"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                                required
                            />
                            <InputError message={form.errors.email} />
                        </div>
                        <Button type="submit" disabled={form.processing}>
                            Send invite
                        </Button>
                    </form>
                )}
                {invites.length > 0 ? (
                    <ul className="divide-y divide-border text-sm">
                        {invites.map((i) => (
                            <li
                                key={i.id}
                                className="flex justify-between gap-4 py-2"
                            >
                                <span>{i.email}</span>
                                <span className="text-muted-foreground">
                                    waiting · until {shortDate(i.expires_at)}
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No invites waiting.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function NewHousehold({ first }: { first: boolean }) {
    const form = useForm({ name: '', currency: 'EUR' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(store.url(), { onSuccess: () => form.reset() });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    {first ? 'Start your household' : 'Start another household'}
                </CardTitle>
                <CardDescription>
                    You become its owner and can invite the people you live
                    with.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    onSubmit={submit}
                    className="grid gap-3 sm:grid-cols-[1fr_8rem_auto] sm:items-end"
                >
                    <div className="grid gap-1">
                        <Label htmlFor="household-name">Name</Label>
                        <Input
                            id="household-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="Our flat"
                            required
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="household-currency">Currency</Label>
                        <Input
                            id="household-currency"
                            value={form.data.currency}
                            onChange={(e) =>
                                form.setData(
                                    'currency',
                                    e.target.value.toUpperCase(),
                                )
                            }
                            maxLength={3}
                            required
                        />
                        <InputError message={form.errors.currency} />
                    </div>
                    <Button type="submit" disabled={form.processing}>
                        Start
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

Household.layout = {
    breadcrumbs: [{ title: 'Household', href: show() }],
};
