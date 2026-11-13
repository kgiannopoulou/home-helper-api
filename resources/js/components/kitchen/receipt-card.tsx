import { Form, router } from '@inertiajs/react';
import { AlertTriangle, Check, ReceiptText, X } from 'lucide-react';
import { useState } from 'react';
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
import { euros, lineAmount } from '@/lib/kitchen';
import { destroy, importMethod, store } from '@/routes/kitchen/receipts';
import type { ReceiptDraft, ReceiptLine } from '@/types/kitchen';

/**
 * Receipts (docs/kitchen.md#receipts): a photo (read on this server by
 * Tesseract) or pasted text becomes a draft; nothing changes until it's
 * checked and imported.
 */
export function ReceiptCard({
    receipt,
    photos,
}: {
    receipt: ReceiptDraft | null;
    photos: boolean;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <ReceiptText className="size-5" /> Receipt
                </CardTitle>
                <CardDescription>
                    A photo of the till slip, or its text. Everything bought
                    goes to the kitchen, the price history and spending, and
                    comes off the list. You check it first.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {receipt ? (
                    <ReceiptReview key={receipt.id} receipt={receipt} />
                ) : (
                    <Form
                        {...store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="flex flex-col gap-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                {photos && (
                                    <div className="grid gap-1">
                                        <Label htmlFor="receipt-photo">
                                            Photo
                                        </Label>
                                        <Input
                                            id="receipt-photo"
                                            name="photo"
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            capture="environment"
                                        />
                                        <InputError message={errors.photo} />
                                    </div>
                                )}
                                <div className="grid gap-1">
                                    <Label htmlFor="receipt-text">
                                        {photos ? 'Or the text' : 'The text'}
                                    </Label>
                                    <textarea
                                        id="receipt-text"
                                        name="text"
                                        rows={3}
                                        className="rounded-md border bg-transparent px-3 py-2 font-mono text-sm"
                                        placeholder={
                                            'ΓΑΛΑ ΦΡΕΣΚΟ 1L   1,89\nΣΥΝΟΛΟ   1,89'
                                        }
                                    />
                                    <InputError message={errors.text} />
                                </div>
                                <Button
                                    type="submit"
                                    className="self-start"
                                    disabled={processing}
                                >
                                    {processing ? 'Reading…' : 'Read it'}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </CardContent>
        </Card>
    );
}

type Choice = { include: boolean; name: string; amount_text: string };

export function ReceiptReview({ receipt }: { receipt: ReceiptDraft }) {
    const [store, setStore] = useState(receipt.store ?? '');
    const [date, setDate] = useState(receipt.purchased_on ?? '');
    const [choices, setChoices] = useState<Choice[]>(() =>
        receipt.lines.map((l) => ({
            include: l.include,
            name: l.item_name ?? l.name,
            amount_text: '',
        })),
    );
    const set = (i: number, patch: Partial<Choice>) =>
        setChoices((c) => c.map((x, j) => (j === i ? { ...x, ...patch } : x)));
    const count = choices.filter((c) => c.include).length;

    const submit = () =>
        router.post(
            importMethod.url(receipt.id),
            {
                store,
                purchased_on: date,
                lines: Object.fromEntries(
                    receipt.lines.map((l, i) => [
                        l.index,
                        {
                            include: choices[i].include,
                            // A matched item stays that item; a new one takes the typed name
                            ...(l.item_id && choices[i].name === l.item_name
                                ? {}
                                : { item_id: null, name: choices[i].name }),
                            amount_text: choices[i].amount_text,
                        },
                    ]),
                ),
            },
            { preserveScroll: true },
        );

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap gap-2">
                <Input
                    className="w-56"
                    value={store}
                    onChange={(e) => setStore(e.target.value)}
                    aria-label="Shop"
                    placeholder="Shop"
                />
                <Input
                    className="w-40"
                    type="date"
                    value={date}
                    onChange={(e) => setDate(e.target.value)}
                    aria-label="Date"
                />
                <Badge variant="outline" className="self-center">
                    Total {receipt.total !== null ? euros(receipt.total) : '?'}
                </Badge>
            </div>
            {!receipt.adds_up && (
                <p className="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400">
                    <AlertTriangle className="size-4" /> The lines don't add up
                    to the total: a line may be missing or misread.
                </p>
            )}
            <ul className="divide-y text-sm">
                {receipt.lines.map((l, i) => (
                    <ReceiptRow
                        key={l.index}
                        line={l}
                        choice={choices[i]}
                        onChange={(patch) => set(i, patch)}
                    />
                ))}
            </ul>
            <div className="flex gap-2">
                <Button onClick={submit}>
                    <Check /> Import {count} {count === 1 ? 'item' : 'items'}
                </Button>
                <Button
                    variant="ghost"
                    onClick={() =>
                        router.delete(destroy.url(receipt.id), {
                            preserveScroll: true,
                        })
                    }
                >
                    <X /> Throw away
                </Button>
            </div>
        </div>
    );
}

function ReceiptRow({
    line,
    choice,
    onChange,
}: {
    line: ReceiptLine;
    choice: Choice;
    onChange: (patch: Partial<Choice>) => void;
}) {
    return (
        <li className="flex flex-wrap items-center gap-2 py-2">
            <input
                type="checkbox"
                checked={choice.include}
                onChange={(e) => onChange({ include: e.target.checked })}
                aria-label={`Add ${line.raw} to the kitchen`}
            />
            <div className="min-w-0 flex-1">
                <Input
                    className="h-8"
                    value={choice.name}
                    disabled={!choice.include}
                    onChange={(e) => onChange({ name: e.target.value })}
                    aria-label={`Name for ${line.raw}`}
                />
                <div className="mt-0.5 text-xs text-muted-foreground">
                    {line.raw}
                    {line.item_id && choice.name === line.item_name
                        ? ' · adds to what you have'
                        : ' · new'}
                </div>
            </div>
            <Input
                className="h-8 w-24"
                value={choice.amount_text}
                disabled={!choice.include}
                onChange={(e) => onChange({ amount_text: e.target.value })}
                placeholder={lineAmount(line) || 'how much?'}
                aria-label={`How much ${line.raw}`}
            />
            <span className="w-16 text-right tabular-nums">
                {euros(line.price)}
                {line.discount > 0 && (
                    <span className="block text-xs text-emerald-600">
                        −{euros(line.discount)}
                    </span>
                )}
            </span>
        </li>
    );
}
