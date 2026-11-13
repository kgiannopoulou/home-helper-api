import { router } from '@inertiajs/react';
import { Minus, Plus, Trash2 } from 'lucide-react';
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
import { expiryText, fullness, LOCATION_LABEL } from '@/lib/kitchen';
import { cn } from '@/lib/utils';
import { add, destroy, take } from '@/routes/kitchen/items';
import type { KitchenItem } from '@/types/kitchen';

const LEVEL_COLOR: Record<string, string> = {
    full: 'bg-emerald-500',
    half: 'bg-amber-400',
    low: 'bg-orange-500',
    empty: 'bg-red-500',
};

/** One storage place: its items with how full they are, use / waste / add */
export function InventoryCard({
    location,
    items,
}: {
    location: string;
    items: KitchenItem[];
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{LOCATION_LABEL[location] ?? location}</CardTitle>
                <CardDescription>
                    {items.length} {items.length === 1 ? 'thing' : 'things'}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col divide-y">
                {items.map((item) => (
                    <ItemRow key={item.id} item={item} />
                ))}
            </CardContent>
        </Card>
    );
}

export function ItemRow({ item }: { item: KitchenItem }) {
    const [amount, setAmount] = useState('');
    const send = (url: string, data: Record<string, string>) =>
        router.post(url, data, {
            preserveScroll: true,
            onSuccess: () => setAmount(''),
        });
    const left = fullness(item);

    return (
        <div className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
            <div className="flex items-center justify-between gap-2">
                <div className="min-w-0">
                    <div className="truncate font-medium">{item.name}</div>
                    <div className="text-sm text-muted-foreground">
                        {item.amount_text || '—'}
                        {item.ingredient &&
                            item.ingredient.toLowerCase() !==
                                item.name.toLowerCase() && (
                                <span> · {item.ingredient}</span>
                            )}
                        {item.expiry &&
                            item.expiry.state !== 'none' &&
                            item.expiry.state !== 'ok' && (
                                <span
                                    className={
                                        item.expiry.state === 'expired'
                                            ? 'text-destructive'
                                            : ''
                                    }
                                >
                                    {' '}
                                    · {expiryText(item)}
                                </span>
                            )}
                    </div>
                </div>
                {item.level && item.level !== 'full' && (
                    <Badge
                        variant={
                            item.level === 'empty' ? 'destructive' : 'outline'
                        }
                    >
                        {item.level}
                    </Badge>
                )}
            </div>
            <div
                className="h-1.5 w-full rounded-full bg-muted"
                role="meter"
                aria-label={`${item.name} left`}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={Math.round(left * 100)}
            >
                <div
                    className={cn(
                        'h-1.5 rounded-full',
                        LEVEL_COLOR[item.level ?? 'full'],
                    )}
                    style={{ width: `${Math.round(left * 100)}%` }}
                />
            </div>
            <div className="flex flex-wrap items-center gap-1">
                <Input
                    className="h-8 w-28"
                    value={amount}
                    onChange={(e) => setAmount(e.target.value)}
                    placeholder={item.unit === 'pcs' ? '2' : '200 g'}
                    aria-label={`How much ${item.name}`}
                />
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        send(take.url(item.id), {
                            kind: 'used',
                            amount_text: amount,
                        })
                    }
                    aria-label={`Used ${item.name}`}
                    title="Used (empty box: all of it)"
                >
                    <Minus /> Used
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        send(take.url(item.id), {
                            kind: 'wasted',
                            amount_text: amount,
                        })
                    }
                    aria-label={`Threw away ${item.name}`}
                >
                    <Trash2 /> Wasted
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={amount.trim() === ''}
                    onClick={() =>
                        send(add.url(item.id), { amount_text: amount })
                    }
                    aria-label={`Add ${item.name}`}
                >
                    <Plus /> Add
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    className="ml-auto text-muted-foreground"
                    onClick={() =>
                        router.delete(destroy.url(item.id), {
                            preserveScroll: true,
                        })
                    }
                    aria-label={`Remove ${item.name}`}
                >
                    Remove
                </Button>
            </div>
        </div>
    );
}
