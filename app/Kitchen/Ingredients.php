<?php

namespace App\Kitchen;

use App\Support\ItemName;

/**
 * One key per ingredient (docs/kitchen.md#ingredients), so the inventory, a
 * receipt, a barcode's product and a recipe talk about the same thing:
 *
 *   "Greek yoghurt 2%", "ΓΙΑΟΥΡΤΙ ΣΤΡΑΓΓΙΣΤΟ", "yogurt"  → yogurt
 *   "Chicken breast fillets", "ΚΟΤΟΠΟΥΛΟ ΣΤΗΘΟΣ"        → chicken
 *   "Extra virgin olive oil", "ΕΛΑΙΟΛΑΔΟ"               → olive oil
 *
 * Folding first (lower case, no Greek accents, σ for ς), then the longest
 * alias found as whole words wins, so "olive oil" beats "oil" and "tomato
 * paste" beats "tomato". A name with no alias keeps its folded, singular self:
 * an unknown ingredient still matches itself.
 */
final class Ingredients
{
    /** key => aliases (folded). The key itself is always an alias. */
    public const ALIASES = [
        'milk' => ['milk', 'γαλα'],
        'yogurt' => ['yogurt', 'yoghurt', 'γιαουρτι'],
        'egg' => ['egg', 'eggs', 'αυγα', 'αυγο'],
        'feta' => ['feta', 'φετα'],
        'cheese' => ['cheese', 'τυρι', 'κασερι', 'γραβιερα', 'gouda', 'cheddar', 'edam'],
        'halloumi' => ['halloumi', 'χαλουμι'],
        'butter' => ['butter', 'βουτυρο'],
        'cream' => ['cream', 'κρεμα γαλακτος'],
        'bread' => ['bread', 'ψωμι', 'loaf'],
        'pita' => ['pita', 'pitta', 'πιτα', 'πιτες'],
        'flour' => ['flour', 'αλευρι'],
        'pasta' => ['pasta', 'spaghetti', 'penne', 'macaroni', 'μακαρονια', 'σπαγγετι', 'πενες', 'κριθαρακι', 'orzo'],
        'rice' => ['rice', 'ρυζι'],
        'lentils' => ['lentil', 'lentils', 'φακεσ'],
        'chickpeas' => ['chickpea', 'chickpeas', 'ρεβυθια'],
        'beans' => ['bean', 'beans', 'φασολια', 'γιγαντεσ'],
        'green beans' => ['green beans', 'φασολακια'],
        'olive oil' => ['olive oil', 'ελαιολαδο', 'ελαιολαδο παρθενο'],
        'oil' => ['oil', 'sunflower oil', 'ηλιελαιο', 'λαδι'],
        'chicken' => ['chicken', 'κοτοπουλο', 'κοτοπουλου'],
        'minced meat' => ['minced meat', 'mince', 'ground beef', 'κιμασ'],
        'pork' => ['pork', 'χοιρινο', 'χοιρινεσ'],
        'beef' => ['beef', 'μοσχαρι', 'μοσχαρισιο'],
        'fish' => ['fish', 'cod', 'salmon', 'ψαρι', 'μπακαλιαροσ', 'σολομοσ'],
        'tuna' => ['tuna', 'τονοσ'],
        'tomato' => ['tomato', 'tomatoes', 'ντοματα', 'ντοματεσ', 'τοματα', 'τοματεσ'],
        'tomato paste' => ['tomato paste', 'passata', 'πελτεσ', 'πασσατα', 'τοματα πολτοσ', 'ντοματα τριμμενη', 'chopped tomatoes'],
        'potato' => ['potato', 'potatoes', 'πατατα', 'πατατεσ'],
        'onion' => ['onion', 'onions', 'κρεμμυδι', 'κρεμμυδια'],
        'garlic' => ['garlic', 'σκορδο'],
        'pepper' => ['pepper', 'bell pepper', 'πιπερια', 'πιπεριεσ'],
        'cucumber' => ['cucumber', 'αγγουρι'],
        'courgette' => ['courgette', 'zucchini', 'κολοκυθακι', 'κολοκυθακια'],
        'aubergine' => ['aubergine', 'eggplant', 'μελιτζανα', 'μελιτζανεσ'],
        'carrot' => ['carrot', 'καροτο', 'καροτα'],
        'spinach' => ['spinach', 'σπανακι'],
        'lettuce' => ['lettuce', 'μαρουλι'],
        'lemon' => ['lemon', 'λεμονι', 'λεμονια'],
        'banana' => ['banana', 'μπανανα', 'μπανανεσ'],
        'apple' => ['apple', 'μηλο', 'μηλα'],
        'orange' => ['orange', 'πορτοκαλι', 'πορτοκαλια'],
        'mushroom' => ['mushroom', 'μανιταρι', 'μανιταρια'],
        'honey' => ['honey', 'μελι'],
        'sugar' => ['sugar', 'ζαχαρη'],
        'coffee' => ['coffee', 'καφεσ'],
        'tea' => ['tea', 'τσαι'],
        'oats' => ['oat', 'oats', 'βρωμη'],
        'filo' => ['filo', 'phyllo', 'φυλλο'],
        'oregano' => ['oregano', 'ριγανη'],
        'salt' => ['salt', 'αλατι'],
        'black pepper' => ['black pepper', 'πιπερι'],
    ];

    /** Assumed to be in every kitchen: recipes don't need them in the inventory */
    public const STAPLES = ['salt', 'black pepper', 'oil', 'olive oil', 'oregano', 'sugar', 'flour'];

    /** Words that describe, not name, an ingredient */
    private const NOISE = '/\b(fresh|organic|bio|extra|virgin|light|low|fat|full|whole|semi|skimmed|greek|strained|plain|large|small|free|range|fillets?|breast|thighs?|sliced|grated|frozen|φρεσκο|φρεσκα|φρεσκοσ|βιολογικο|στραγγιστο|ελαφρυ|πληρεσ|στηθοσ|φιλετο|κατεψυγμενο)\b/u';

    /** Lower case, no Greek accents, σ for final ς, single spaces */
    public static function fold(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $t = strtr($t, ['ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ϊ' => 'ι', 'ΐ' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ώ' => 'ω', 'ς' => 'σ']);

        return (string) preg_replace('/\s+/u', ' ', $t);
    }

    /** "Greek yoghurt 2%" → "yogurt" */
    public static function key(string $name): string
    {
        $folded = self::fold($name);
        $best = null;
        $bestLength = 0;
        foreach (self::index() as $alias => $key) {
            $length = mb_strlen($alias);
            if ($length > $bestLength && preg_match('/(?<![\p{L}])'.preg_quote($alias, '/').'(?![\p{L}])/u', $folded)) {
                $best = $key;
                $bestLength = $length;
            }
        }
        if ($best !== null) {
            return $best;
        }

        // Unknown: the name without numbers, units and describing words, singular
        $plain = (string) preg_replace(['/\d+([.,]\d+)?\s*(%|g|gr|kg|ml|l|lt|cl)?\b/u', self::NOISE, '/[^\p{L} ]/u'], ' ', $folded);
        $plain = trim((string) preg_replace('/\s+/u', ' ', $plain));

        return mb_substr(ItemName::normalize($plain !== '' ? $plain : $folded), 0, 60);
    }

    public static function isStaple(string $key): bool
    {
        return in_array($key, self::STAPLES, true);
    }

    /** @return array<string, string> folded alias => key */
    private static function index(): array
    {
        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (self::ALIASES as $key => $aliases) {
                foreach ([$key, ...$aliases] as $alias) {
                    $index[self::fold($alias)] = $key;
                }
            }
        }

        return $index;
    }
}
