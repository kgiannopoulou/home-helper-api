import { router } from '@inertiajs/react';
import { CalendarClock, Check, PackageOpen, Trash2 } from 'lucide-react';
import { useState } from 'react';
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
import { euros, expiryText } from '@/lib/kitchen';
import { expiry, opened, stillGood, take } from '@/routes/kitchen/items';
import type { KitchenItem, Waste } from '@/types/kitchen';

/**
 * Use soon (docs/kitchen.md#expiry): what's in the kitchen and goes off
 * within three days, or has. Each can be used up, thrown away, marked opened,
 * given the date on the pack, or kept a little longer.
 */
export function ExpiringCard({ items }: { items: KitchenItem[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <CalendarClock className="size-5" /> Use soon
                </CardTitle>
                <CardDescription>
                    Going off within three days. Dates marked "about" are
                    estimates: check the pack.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {items.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing is about to go off.
                    </p>
                ) : (
                    <ul className="flex flex-col divide-y">
                        {items.map((i) => (
                            <ExpiringRow key={i.id} item={i} />
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function ExpiringRow({ item }: { item: KitchenItem }) {
    const [date, setDate] = useState(item.expires_on ?? '');
    const post = (url: string, data: Record<string, string> = {}) =>
        router.post(url, data, { preserveScroll: true });
    const expired = item.expiry?.state === 'expired';

    return (
        <li className="flex flex-col gap-2 py-2 first:pt-0 last:pb-0">
            <div className="flex items-center justify-between gap-2">
                <div>
                    <span className="font-medium">{item.name}</span>
                    <span className="text-sm text-muted-foreground">
                        {' '}
                        · {item.amount_text || 'some'}
                    </span>
                </div>
                <Badge variant={expired ? 'destructive' : 'outline'}>
                    {expiryText(item)}
                </Badge>
            </div>
            <div className="flex flex-wrap items-center gap-1">
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => post(take.url(item.id), { kind: 'used' })}
                    aria-label={`Used up ${item.name}`}
                >
                    <Check /> Used up
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        post(take.url(item.id), {
                            kind: expired ? 'expired' : 'wasted',
                        })
                    }
                    aria-label={`Threw away ${item.name}`}
                >
                    <Trash2 /> Thrown away
                </Button>
                {expired ? (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => post(stillGood.url(item.id))}
                        aria-label={`${item.name} is still good`}
                    >
                        Still good
                    </Button>
                ) : (
                    !item.opened_on && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => post(opened.url(item.id))}
                            aria-label={`Opened ${item.name}`}
                        >
                            <PackageOpen /> Opened
                        </Button>
                    )
                )}
                <Input
                    type="date"
                    className="h-8 w-36"
                    value={date}
                    onChange={(e) => setDate(e.target.value)}
                    onBlur={() =>
                        date &&
                        date !== item.expires_on &&
                        post(expiry.url(item.id), { expires_on: date })
                    }
                    aria-label={`Date on the pack of ${item.name}`}
                />
            </div>
        </li>
    );
}

/** What went in the bin in the last 30 days, and roughly what it cost */
export function WasteCard({ waste }: { waste: Waste }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Trash2 className="size-5" /> Thrown away
                </CardTitle>
                <CardDescription>
                    The last {waste.days} days.{' '}
                    {waste.items.length > 0 &&
                        (waste.priced > 0
                            ? `About ${euros(waste.value)}, from what the last packs cost.`
                            : 'No prices yet to say what it cost.')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {waste.items.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing thrown away. 🎉
                    </p>
                ) : (
                    <ul className="divide-y text-sm">
                        {waste.items.map((w) => (
                            <li
                                key={w.id}
                                className="flex justify-between gap-2 py-1.5"
                            >
                                <span>
                                    {w.name}{' '}
                                    <span className="text-muted-foreground">
                                        ×{w.times}
                                    </span>
                                </span>
                                <span className="tabular-nums">
                                    {w.value !== null ? euros(w.value) : '—'}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
