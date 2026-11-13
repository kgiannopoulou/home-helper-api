<?php

namespace App\Kitchen\Receipts;

use App\Kitchen\Ingredients;
use App\Kitchen\Units;
use Carbon\CarbonImmutable;

/**
 * Reads a Greek supermarket receipt's text (OCR or pasted) into lines
 * (docs/kitchen.md#receipts). Nothing here is trusted: the result is a
 * draft that a person checks before anything is saved.
 *
 *   ΓΑΛΑ ΦΡΕΣΚΟ 1L            1,89 Β      an item: name, price, VAT letter
 *   2 X 1,15                              a quantity: two of the item next to it
 *   0,850 KG X 2,40                       a weight: 850 g at 2,40 a kilo
 *   ΕΚΠΤΩΣΗ                  -0,60        a discount on the item before it
 *   ΣΥΝΟΛΟ                   23,98        the total; the items end here
 *
 * A quantity line belongs to the neighbour whose price it explains
 * (quantity × unit price), the next line first: chains print it on either
 * side. Keywords are compared as OCR skeletons (OcrText), so ΕΚΗΤΩΣΗ is
 * still a discount.
 */
final class ReceiptParser
{
    /** Header and footer lines that are never items */
    private const SKIP = ['αφμ', 'δου', 'αρ', 'αποδειξη', 'ταμειο', 'ταμιασ', 'τηλ', 'φπα', 'καρτα', 'μετρητα', 'ρεστα', 'πληρωμη', 'ευχαριστουμε', 'αντιτυπο', 'αριθμοσ', 'τεμαχια', 'ειδη', 'visa', 'mastercard', 'debit', 'auth', 'pos', 'mark', 'uid', 'qr'];

    private const TOTAL = ['συνολο', 'πληρωτεο', 'total', 'γενικο συνολο', 'συν πληρωτεο'];

    private const SUBTOTAL = ['μερικο συνολο', 'υποσυνολο', 'subtotal'];

    private const DISCOUNT = ['εκπτωση', 'εκπτ', 'προσφορα', 'discount', 'κουπονι'];

    /** Known chains: skeleton word => the name to show */
    private const CHAINS = [
        'σκλαβενιτησ' => 'Sklavenitis', 'βασιλοπουλοσ' => 'AB Vassilopoulos', 'lidl' => 'Lidl', 'μασουτησ' => 'Masoutis',
        'my market' => 'My Market', 'κρητικοσ' => 'Kritikos', 'γαλαξιασ' => 'Galaxias', 'bazaar' => 'Bazaar', 'market in' => 'Market In',
        'θανοπουλοσ' => 'Thanopoulos', 'χαλκιαδακησ' => 'Chalkiadakis',
    ];

    /** Not food: these don't go to the kitchen unless someone ticks them */
    private const NOT_FOOD = ['χαρτι', 'απορρυπαντικο', 'σαπουνι', 'σακουλα', 'σακουλεσ', 'οδοντοκρεμα', 'σαμπουαν', 'χλωρινη', 'μαλακτικο', 'αλουμινοχαρτο', 'μεμβρανη', 'πανες', 'μπαταριεσ', 'λαμπα', 'σφουγγαρι', 'αφρολουτρο', 'καθαριστικο', 'σκουπιδιων'];

    private const PRICE = '-?\d{1,4}[.,]\d{2}';

    public function parse(string $text): ParsedReceipt
    {
        $lines = array_values(array_filter(array_map([OcrText::class, 'line'], preg_split('/\R/u', $text) ?: []), fn ($l) => $l !== ''));

        $store = $this->store($lines);
        $date = $this->date($lines);
        $total = null;
        $items = [];
        $pendingQuantity = null;

        foreach ($lines as $i => $line) {
            if ($this->isAny($line, self::SUBTOTAL)) {
                continue;
            }
            if ($this->isAny($line, self::TOTAL) && ($price = $this->lastPrice($line)) !== null) {
                $total = $price;

                break;
            }
            if (preg_match('/^[-=_*.\s]{5,}$/u', $line) || $this->isAny($line, self::SKIP)) {
                continue;
            }
            // 2 X 1,15 / 0,850 KG X 2,40 / 2 ΤΕΜ x 1,15
            if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(kg|κιλ\p{L}*|κγ|τεμ\p{L}*|τμχ|τεμ\.)?\s*[xχ×*]\s*('.self::PRICE.')(?:\s*\/?\s*\p{L}*)?$/u', OcrText::greek($line), $m)) {
                $quantity = ['count' => self::number($m[1]), 'weighed' => str_starts_with($m[2], 'k') || str_starts_with($m[2], 'κ'), 'unit_price' => self::number($m[3])];
                // Belongs to the item before it if that item's price is quantity × unit price
                $last = $items === [] ? null : count($items) - 1;
                if ($last !== null && $items[$last]['quantity'] === null && self::explains($quantity, $items[$last]['price'])
                    && ! self::explains($quantity, $this->nextPrice($lines, $i))) {
                    $items[$last] = $this->withQuantity($items[$last], $quantity);
                } else {
                    $pendingQuantity = $quantity;
                }

                continue;
            }
            if (! preg_match('/^(.*?\p{L}.*?)\s+('.self::PRICE.')(?:\s+[\p{L}\d%]{1,3})?$/u', $line, $m)) {
                continue;
            }
            $name = trim($m[1]);
            $price = self::number($m[2]);

            if ($this->isAny($name, self::DISCOUNT) || $price < 0) {
                if ($items !== []) {
                    $last = count($items) - 1;
                    $items[$last]['discount'] = round($items[$last]['discount'] + abs($price), 2);
                    $items[$last]['price'] = round($items[$last]['price'] - abs($price), 2);
                }

                continue;
            }

            $item = $this->item($name, $price);
            if ($pendingQuantity !== null && self::explains($pendingQuantity, $price)) {
                $item = $this->withQuantity($item, $pendingQuantity);
            }
            $pendingQuantity = null;
            $items[] = $item;
        }

        $sum = round(array_sum(array_column($items, 'price')), 2);

        return new ParsedReceipt(
            store: $store,
            purchasedOn: $date,
            total: $total,
            lines: $items,
            addsUp: $total !== null && abs($sum - $total) < 0.015,
            sum: $sum,
        );
    }

    /**
     * @return array{name: string, raw: string, price: float, discount: float, quantity: float|null, weighed: bool, unit_price: float|null, amount: float|null, unit: string|null, ingredient: string, food: bool}
     */
    private function item(string $raw, float $price): array
    {
        $greek = OcrText::greek($raw);
        $ingredient = Ingredients::key($greek);
        $known = array_key_exists($ingredient, Ingredients::ALIASES);
        if (! $known && ($bySkeleton = self::ingredientBySkeleton($raw))) {
            [$ingredient, $known] = [$bySkeleton, true];
        }
        $pack = self::packSize($greek);

        return [
            'name' => self::displayName($raw),
            'raw' => $raw,
            'price' => $price,
            'discount' => 0.0,
            'quantity' => null,
            'weighed' => false,
            'unit_price' => null,
            'amount' => $pack[0] ?? null,
            'unit' => $pack[1] ?? null,
            'ingredient' => $ingredient,
            'food' => $known || ! $this->isAny($raw, self::NOT_FOOD),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{count: float, weighed: bool, unit_price: float}  $q
     * @return array<string, mixed>
     */
    private function withQuantity(array $item, array $q): array
    {
        $item['quantity'] = $q['count'];
        $item['weighed'] = $q['weighed'];
        $item['unit_price'] = $q['unit_price'];
        if ($q['weighed']) {
            [$item['amount'], $item['unit']] = [round($q['count'] * 1000, 3), 'g'];
        } elseif ($item['amount'] !== null) {
            // 2 × 500 g
            $item['amount'] = round($item['amount'] * $q['count'], 3);
        } else {
            [$item['amount'], $item['unit']] = [$q['count'], 'pcs'];
        }

        return $item;
    }

    /**
     * "500ΓΡ", "1KG", "1L", "6ΤΕΜ" in a product name; only with an explicit unit ("2%" is not an amount)
     *
     * @return array{0: float, 1: string}|null
     */
    public static function packSize(string $name): ?array
    {
        $n = Ingredients::fold($name);
        // OCR reads ΓΡ as ΤΡ
        $n = (string) preg_replace('/(\d)\s*τρ\b/u', '$1γρ', $n);
        $n = (string) preg_replace('/(\d),(\d)/u', '$1.$2', $n);
        if (preg_match('/(\d+(?:\.\d+)?)\s*(kg|κιλο|γρ|gr|g|ml|lt|l|λιτ|τεμ|τμχ)(?!\p{L})/u', $n, $m)) {
            return Units::toBase((float) $m[1], match ($m[2]) {
                'λιτ' => 'l', 'τμχ' => 'pcs', default => $m[2],
            });
        }

        return null;
    }

    /** "ΓΙΑΟΥΡΤΙ ΣΤΡΑΓΓΙΣΤΟ 1KG" → "Γιαουρτι στραγγιστο 1kg": readable, as printed */
    public static function displayName(string $raw): string
    {
        $greek = OcrText::greek($raw);
        $name = mb_strtoupper(mb_substr($greek, 0, 1)).mb_substr($greek, 1);

        return mb_substr(trim($name), 0, 100);
    }

    /** An ingredient whose Greek alias matches the name's OCR skeleton (ΤΑΛΑ → milk) */
    public static function ingredientBySkeleton(string $raw): ?string
    {
        $skeleton = ' '.OcrText::skeleton($raw).' ';
        $best = null;
        $bestLength = 0;
        foreach (Ingredients::ALIASES as $key => $aliases) {
            foreach ($aliases as $alias) {
                $s = OcrText::skeleton($alias);
                if (mb_strlen($s) > $bestLength && mb_strlen($s) >= 4 && preg_match('/(?<!\p{L})'.preg_quote($s, '/').'(?!\p{L})/u', $skeleton)) {
                    [$best, $bestLength] = [$key, mb_strlen($s)];
                }
            }
        }

        return $best;
    }

    /** @param list<string> $lines */
    private function store(array $lines): ?string
    {
        $head = array_slice($lines, 0, 8);
        foreach ($head as $line) {
            foreach (self::CHAINS as $word => $name) {
                if (OcrText::has($line, $word)) {
                    return $name;
                }
            }
        }
        foreach ($head as $line) {
            if (preg_match('/\p{L}{3,}/u', $line) && ! preg_match('/\d{3,}/', $line) && ! $this->isAny($line, self::SKIP)) {
                return mb_substr(self::displayName($line), 0, 100);
            }
        }

        return null;
    }

    /** @param list<string> $lines */
    private function date(array $lines): ?CarbonImmutable
    {
        foreach ($lines as $line) {
            if (preg_match('/(?<!\d)(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4}|\d{2})(?!\d)/', $line, $m)) {
                $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
                if (checkdate((int) $m[2], (int) $m[1], $year)) {
                    return CarbonImmutable::create($year, (int) $m[2], (int) $m[1]);
                }
            }
        }

        return null;
    }

    /** @param list<string> $words */
    private function isAny(string $line, array $words): bool
    {
        foreach ($words as $w) {
            if (OcrText::has($line, $w)) {
                return true;
            }
        }

        return false;
    }

    private function lastPrice(string $line): ?float
    {
        return preg_match_all('/'.self::PRICE.'/', $line, $m) ? self::number(end($m[0])) : null;
    }

    /** @param list<string> $lines */
    private function nextPrice(array $lines, int $i): ?float
    {
        $next = $lines[$i + 1] ?? null;
        // Only an item: the total and discounts don't take quantities
        if ($next === null || $this->isAny($next, [...self::TOTAL, ...self::SUBTOTAL, ...self::DISCOUNT, ...self::SKIP])) {
            return null;
        }

        return preg_match('/\s('.self::PRICE.')(?:\s+[\p{L}\d%]{1,3})?$/u', $next, $m) ? self::number($m[1]) : null;
    }

    /** @param array{count: float, weighed: bool, unit_price: float} $q */
    private static function explains(array $q, ?float $price): bool
    {
        return $price !== null && abs($q['count'] * $q['unit_price'] - $price) <= 0.011;
    }

    private static function number(string $s): float
    {
        return (float) str_replace(',', '.', $s);
    }
}
