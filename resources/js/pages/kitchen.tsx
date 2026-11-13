import { Form, Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import InputError from '@/components/input-error';
import Heading from '@/components/heading';
import { BarcodeScanner } from '@/components/kitchen/barcode-scanner';
import { ExpiringCard, WasteCard } from '@/components/kitchen/expiry-cards';
import { InventoryCard } from '@/components/kitchen/inventory-card';
import { ReceiptCard } from '@/components/kitchen/receipt-card';
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
import { byLocation, LOCATION_LABEL } from '@/lib/kitchen';
import { kitchen as kitchenRoute } from '@/routes';
import { store } from '@/routes/kitchen/items';
import type {
    KitchenItem,
    Movement,
    ReceiptDraft,
    Waste,
} from '@/types/kitchen';

type Props = {
    items: KitchenItem[];
    movements: Movement[];
    locations: string[];
    /** The newest receipt waiting to be checked */
    receipt: ReceiptDraft | null;
    /** Photos can be read here (KITCHEN_OCR) */
    photos: boolean;
    /** In stock and going off within three days */
    expiring: KitchenItem[];
    waste: Waste;
};

const KIND_LABEL: Record<Movement['kind'], string> = {
    bought: 'Added',
    used: 'Used',
    cooked: 'Cooked',
    wasted: 'Wasted',
    expired: 'Expired',
    adjusted: 'Counted',
};

/**
 * The Kitchen (docs/kitchen.md): what's in the fridge, freezer and
 * cupboards, how much, and what came and went.
 */
export default function Kitchen({
    items,
    movements,
    locations,
    receipt,
    photos,
    expiring,
    waste,
}: Props) {
    const groups = byLocation(items);
    const kitchenPlaces = locations.filter(
        (l) => l !== 'bathroom' && l !== 'cleaning',
    );

    return (
        <>
            <Head title="Kitchen" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Kitchen"
                    description="What's in the fridge, freezer and cupboards, and how much."
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    <BarcodeScanner locations={kitchenPlaces} />

                    <Card>
                        <CardHeader>
                            <CardTitle>Add to the kitchen</CardTitle>
                            <CardDescription>
                                Amounts like "500 g", "1,5 kg", "2 x 1 l" or
                                "6". Greek names work too.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...store.form()}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="flex flex-wrap items-end gap-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-1">
                                            <Label htmlFor="kitchen-name">
                                                Name
                                            </Label>
                                            <Input
                                                id="kitchen-name"
                                                name="name"
                                                required
                                                placeholder="Greek yoghurt"
                                            />
                                            <InputError message={errors.name} />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="kitchen-location">
                                                Where
                                            </Label>
                                            <select
                                                id="kitchen-location"
                                                name="location"
                                                defaultValue="fridge"
                                                className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                            >
                                                {kitchenPlaces.map((l) => (
                                                    <option key={l} value={l}>
                                                        {LOCATION_LABEL[l] ?? l}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="kitchen-amount">
                                                How much
                                            </Label>
                                            <Input
                                                id="kitchen-amount"
                                                name="amount_text"
                                                placeholder="500 g"
                                                className="w-32"
                                            />
                                            <InputError
                                                message={errors.amount_text}
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            <Plus /> Add
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <ExpiringCard items={expiring} />
                    <WasteCard waste={waste} />
                </div>

                <ReceiptCard receipt={receipt} photos={photos} />

                {groups.length === 0 ? (
                    <p className="text-muted-foreground">
                        Nothing in the kitchen yet.
                    </p>
                ) : (
                    <div className="grid gap-6 lg:grid-cols-2 xl:grid-cols-3">
                        {groups.map((g) => (
                            <InventoryCard
                                key={g.location}
                                location={g.location}
                                items={g.items}
                            />
                        ))}
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>What came and went</CardTitle>
                        <CardDescription>
                            Every amount added, used, cooked or thrown away,
                            newest first
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {movements.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing yet.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {movements.map((m) => (
                                    <li
                                        key={m.id}
                                        className="flex justify-between gap-2 py-2"
                                    >
                                        <span>
                                            {KIND_LABEL[m.kind]}{' '}
                                            <span className="font-medium">
                                                {m.item}
                                            </span>
                                            {m.by && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    · {m.by}
                                                </span>
                                            )}
                                        </span>
                                        <span
                                            className={
                                                m.amount < 0
                                                    ? 'text-muted-foreground'
                                                    : 'text-emerald-600'
                                            }
                                        >
                                            {m.label}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Kitchen.layout = {
    breadcrumbs: [{ title: 'Kitchen', href: kitchenRoute() }],
};
