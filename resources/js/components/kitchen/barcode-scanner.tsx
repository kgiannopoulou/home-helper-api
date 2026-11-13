import { router } from '@inertiajs/react';
import { Camera, CameraOff, Minus, Plus, ScanBarcode } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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
import { isBarcode, scanResult } from '@/lib/barcodes';
import { LOCATION_LABEL } from '@/lib/kitchen';
import { apply, lookup } from '@/routes/kitchen/barcodes';
import type { ScanResult } from '@/types/kitchen';

type Detector = {
    detect: (v: HTMLVideoElement) => Promise<{ rawValue: string }[]>;
};
type DetectorClass = new (o: { formats: string[] }) => Detector;

const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e'];

/**
 * Scan a pack (docs/kitchen.md#barcodes): the camera with the browser's own
 * BarcodeDetector (Chrome, Edge, Android), else ZXing (Safari, Firefox),
 * else type the digits. A USB scanner types them too, with Enter.
 */
export function BarcodeScanner({ locations }: { locations: string[] }) {
    const [code, setCode] = useState('');
    const [camera, setCamera] = useState(false);
    const [result, setResult] = useState<ScanResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const look = async (raw: string) => {
        const digits = raw.replace(/\D/g, '');
        if (!isBarcode(digits)) {
            setError("That isn't a product barcode (check the digits).");

            return;
        }
        setBusy(true);
        setError(null);
        try {
            const response = await fetch(lookup.url(digits), {
                headers: { Accept: 'application/json' },
            });
            const body = await response.json();
            if (!response.ok) {
                setError(body.errors?.code?.[0] ?? body.message ?? 'Not found');
                setResult(null);
            } else {
                setResult(scanResult(body));
            }
        } catch {
            setError("Home Helper isn't answering.");
        } finally {
            setBusy(false);
        }
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <ScanBarcode className="size-5" /> Scan a pack
                </CardTitle>
                <CardDescription>
                    The barcode finds the product and its size: one tap adds a
                    pack. Only the barcode is sent to Open Food Facts.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        void look(code);
                    }}
                >
                    <Input
                        className="w-48"
                        inputMode="numeric"
                        value={code}
                        onChange={(e) => setCode(e.target.value)}
                        placeholder="5201054017128"
                        aria-label="Barcode"
                    />
                    <Button type="submit" variant="outline" disabled={busy}>
                        Look up
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setCamera((c) => !c)}
                    >
                        {camera ? <CameraOff /> : <Camera />}
                        {camera ? 'Stop camera' : 'Camera'}
                    </Button>
                </form>
                {camera && (
                    <CameraView
                        onCode={(c) => {
                            setCamera(false);
                            setCode(c);
                            void look(c);
                        }}
                        onError={(m) => {
                            setCamera(false);
                            setError(m);
                        }}
                    />
                )}
                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                {result && (
                    <ScanResultView
                        key={result.gtin}
                        result={result}
                        locations={locations}
                        onDone={() => {
                            setResult(null);
                            setCode('');
                        }}
                    />
                )}
            </CardContent>
        </Card>
    );
}

export function ScanResultView({
    result,
    locations,
    onDone,
}: {
    result: ScanResult;
    locations: string[];
    onDone: () => void;
}) {
    const { item, suggestion, product } = result;
    const [joinSuggestion, setJoin] = useState(suggestion !== null);
    const [name, setName] = useState(product?.name ?? '');
    const [location, setLocation] = useState(product?.location ?? 'pantry');
    const [amount, setAmount] = useState('');
    const send = (action: 'add' | 'use') =>
        router.post(
            apply.url(result.gtin),
            {
                action,
                item_id: !item && joinSuggestion ? (suggestion?.id ?? '') : '',
                name: item ? '' : name,
                location: item ? '' : location,
                amount_text: amount,
            },
            { preserveScroll: true, onSuccess: onDone },
        );
    const pack = product?.quantity ?? (item?.amount_text || null);

    return (
        <div className="flex flex-col gap-3 rounded-lg border p-3">
            <div className="flex items-start gap-3">
                {product?.image_url && (
                    <img
                        src={product.image_url}
                        alt=""
                        className="size-14 rounded object-contain"
                    />
                )}
                <div className="min-w-0">
                    <div className="font-medium">
                        {item?.name ?? product?.name ?? 'Unknown product'}
                    </div>
                    <div className="text-sm text-muted-foreground">
                        {[product?.brand, pack, result.gtin]
                            .filter(Boolean)
                            .join(' · ')}
                    </div>
                    {item && (
                        <Badge variant="outline" className="mt-1">
                            In the kitchen: {item.amount_text || 'some'}
                        </Badge>
                    )}
                    {result.lookup_error && (
                        <p className="text-sm text-muted-foreground">
                            {result.lookup_error}
                        </p>
                    )}
                </div>
            </div>

            {!item && suggestion && (
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={joinSuggestion}
                        onChange={(e) => setJoin(e.target.checked)}
                    />
                    It's our {suggestion.name} (
                    {suggestion.amount_text || 'some'})
                </label>
            )}
            {!item && !(suggestion && joinSuggestion) && (
                <div className="flex flex-wrap gap-2">
                    <Input
                        className="w-56"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="What is it?"
                        aria-label="Product name"
                    />
                    <select
                        value={location}
                        onChange={(e) => setLocation(e.target.value)}
                        aria-label="Where it goes"
                        className="h-9 rounded-md border bg-transparent px-3 text-sm"
                    >
                        {locations.map((l) => (
                            <option key={l} value={l}>
                                {LOCATION_LABEL[l] ?? l}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    className="w-32"
                    value={amount}
                    onChange={(e) => setAmount(e.target.value)}
                    placeholder={pack ?? '1'}
                    aria-label="How much (empty: one pack)"
                />
                <Button
                    size="sm"
                    onClick={() => send('add')}
                    disabled={
                        !item &&
                        !(suggestion && joinSuggestion) &&
                        name.trim() === ''
                    }
                >
                    <Plus /> Add {amount ? amount : 'a pack'}
                </Button>
                {item && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => send('use')}
                    >
                        <Minus /> Used {amount ? amount : 'a pack'}
                    </Button>
                )}
                <Button size="sm" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </div>
    );
}

function CameraView({
    onCode,
    onError,
}: {
    onCode: (code: string) => void;
    onError: (message: string) => void;
}) {
    const video = useRef<HTMLVideoElement>(null);
    // The latest callbacks, without restarting the camera when they change
    const handlers = useRef({ onCode, onError });
    useEffect(() => {
        handlers.current = { onCode, onError };
    });

    useEffect(() => {
        const { onCode: found, onError: failed } = {
            onCode: (c: string) => handlers.current.onCode(c),
            onError: (m: string) => handlers.current.onError(m),
        };
        let stop = () => {};
        let stopped = false;

        const start = async () => {
            const el = video.current;
            if (!el || !navigator.mediaDevices?.getUserMedia) {
                failed(
                    "This browser can't use the camera here. Type the digits instead.",
                );

                return;
            }
            const Native = (
                window as unknown as { BarcodeDetector?: DetectorClass }
            ).BarcodeDetector;
            try {
                if (Native) {
                    const stream = await navigator.mediaDevices.getUserMedia({
                        video: { facingMode: 'environment' },
                    });
                    el.srcObject = stream;
                    await el.play();
                    const detector = new Native({ formats: FORMATS });
                    const timer = window.setInterval(() => {
                        void detector.detect(el).then((codes) => {
                            const hit = codes.find((c) =>
                                isBarcode(c.rawValue),
                            );
                            if (hit && !stopped) {
                                stopped = true;
                                found(hit.rawValue);
                            }
                        });
                    }, 250);
                    stop = () => {
                        window.clearInterval(timer);
                        stream.getTracks().forEach((t) => t.stop());
                    };
                } else {
                    const { BrowserMultiFormatReader } =
                        await import('@zxing/browser');
                    const controls =
                        await new BrowserMultiFormatReader().decodeFromVideoDevice(
                            undefined,
                            el,
                            (res) => {
                                if (
                                    res &&
                                    isBarcode(res.getText()) &&
                                    !stopped
                                ) {
                                    stopped = true;
                                    found(res.getText());
                                }
                            },
                        );
                    stop = () => controls.stop();
                }
            } catch {
                failed(
                    "No camera, or it wasn't allowed. Type the digits instead.",
                );
            }
        };
        void start();

        return () => {
            stopped = true;
            stop();
        };
    }, []);

    return (
        <video
            ref={video}
            className="aspect-video w-full max-w-md rounded-lg bg-black object-cover"
            muted
            playsInline
            aria-label="Camera"
        />
    );
}
