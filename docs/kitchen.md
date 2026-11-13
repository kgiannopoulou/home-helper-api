# Kitchen

The kitchen part of Home Helper: what's in the fridge, freezer and cupboards and how much,
built in [Phase 7](phase-7.md) on top of the phone's inventory, shopping list and purchases.

## Pieces

| | |
|---|---|
| `app/Kitchen/Units` | amounts: parse "1,5 kg", "2 x 500g", "6 eggs"; base units; format |
| `app/Kitchen/Ingredients` | one key per ingredient, English and Greek |
| `app/Kitchen/Pantry` | every change of an amount, recorded and locked |
| `app/Http/Controllers/Api/KitchenController` | use, add and history for the phone |
| `app/Http/Controllers/Web/KitchenController` | the Kitchen page |

Tables: `inventory_items` (since Phase 1, extended), `kitchen_movements`
([diagram](database.md)).

## Amounts and units

An inventory item had a level (full, half, low, empty) and a free-text quantity, which is what
the phone app still sends. Phase 7 adds a measured **amount** in one of three base units:

| Base unit | Also accepted |
|---|---|
| `g` | mg, kg, κιλό, γρ |
| `ml` | cl, dl, l, λίτρο, tsp (5 ml), tbsp (15 ml), cup (240 ml) |
| `pcs` | a bare number, "6 eggs", "x6", τεμ |

"2 x 500g" is 1,000 g, and "1,5 kg" with a decimal comma (as on Greek packs and receipts) is
1,500 g. Grams and millilitres convert 1:1. That's water's density: close enough for milk,
yoghurt and oil, and an approximation everywhere else. Pieces never convert to weight.

`full_amount` is the most an item has held, so the **level follows the amount**: above 60 % of
full is `full`, above 30 % `half`, anything left `low`, nothing `empty`. A level sent by the
phone together with an amount wins. A quantity text the phone sends ("500 g") fills in the
amount when none was given. Existing items got amounts from their quantity text in the
migration.

## Ingredients

Recipes, receipts and barcodes all have to agree that "Greek yoghurt 2%", "ΓΙΑΟΥΡΤΙ
ΣΤΡΑΓΓΙΣΤΟ" and "yogurt" are the same thing. `Ingredients::key()` folds the name (lower case,
no Greek accents, σ for ς), then picks the **longest alias found as whole words**:

| Name | Key |
|---|---|
| Greek yoghurt 2%, ΓΙΑΟΥΡΤΙ ΣΤΡΑΓΓΙΣΤΟ | `yogurt` |
| Chicken breast fillets, ΚΟΤΟΠΟΥΛΟ ΣΤΗΘΟΣ | `chicken` |
| Extra virgin olive oil, ΕΛΑΙΟΛΑΔΟ | `olive oil` (not `oil`) |
| Chopped tomatoes | `tomato paste` (not `tomato`) |
| Πιπεριές Φλωρίνης / Πιπέρι | `pepper` / `black pepper` |
| Fresh Tahini 300g (unknown) | `tahini` |

An unknown name keeps itself, without numbers, units and describing words, so it still matches
itself. The key is stored on the item and follows the name unless someone set it. Some
ingredients (salt, pepper, oil, olive oil, oregano, sugar, flour) are **staples** that a recipe
may assume.

## Movements

Every change of an amount is a `kitchen_movements` row: `bought`, `used`, `cooked`, `wasted`,
`expired` or `adjusted` (counted), signed, in the item's unit, with who and when. `Pantry` does
it in one transaction with the item row locked, so two phones using the last eggs can't both
succeed.

- **Never below zero.** Using more than there is takes what there is; the movement records what
  really left.
- **No amount means all of it.** "Used it up", "threw it away".
- **Counting** ("there are actually 3 left") records the difference as `adjusted`.
- **Units must fit.** Pieces for something weighed are refused. Grams for something in
  millilitres are converted, and the item keeps its unit.

## API

| | |
|---|---|
| `GET/POST/PATCH/DELETE /api/households/{h}/inventory-items` | as before, plus `ingredient`, `amount`, `unit`, `full_amount` (optional) and `amount_text` in answers |
| `POST /api/households/{h}/inventory-items/{item}/use` | `{kind: used\|cooked\|wasted\|expired, amount?, unit?}`, no amount = all of it |
| `POST /api/households/{h}/inventory-items/{item}/add` | `{amount, unit?}` |
| `GET  /api/households/{h}/kitchen/movements` | `?item=&kind=`, newest first |

## The Kitchen page

`/kitchen`: everything by storage place (fridge, freezer, cupboards) with a bar for how much is
left; type an amount, then **Used**, **Wasted** or **Add**. An empty box means all of it, and a
bare number is in the item's own unit. Below that, what came and went. The cleaning cupboard
and bathroom stay on the phone.
