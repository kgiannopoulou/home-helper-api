# Phase 7: Kitchen and shopping intelligence

**Goal:** make the kitchen smarter.
**Deliverable:** an intelligent kitchen assistant that helps reduce waste and manage shopping.
How it works: [kitchen.md](kitchen.md).

| Day | Task | Result |
|---:|---|---|
| 85 | Improve food inventory and ingredient tracking | Amounts in base units (g, ml, pieces) parsed from "1,5 kg", "2 x 500g" or "6 αυγά"; the level follows the amount. One ingredient key per food, in English and Greek. Every change is a `kitchen_movements` row (bought, used, cooked, wasted, expired, counted), locked so two phones can't both take the last egg. The Kitchen page, and use/add endpoints for the phone |
| 86 | Add barcode scanning | The camera on the Kitchen page (the browser's `BarcodeDetector`, ZXing where it's missing, or typed digits). Check digits refuse misreads before any lookup. Products from Open Food Facts (only the barcode is sent; cached 30 days, misses 7), the storage place from its categories, the ingredient from its English name or categories. The barcode and pack size stay on the item, so the next scan is one tap even for a product nobody else knows; "is this your yoghurt?" joins a new barcode to an existing item |
| 87 | Add receipt and grocery OCR | A photo (read on the server by Tesseract, Greek and English) or pasted text becomes a draft to check. Tesseract was run on a real receipt image first: it reads Greek capitals as Latin ones and swaps lookalikes (`ΤΑΛΑ` for ΓΑΛΑ), so words are compared as OCR skeletons. The parser reads items, "2 X 1,15" quantities on either side, weighed lines, discounts and the total, and says when the lines don't add up. Import makes the shopping trip, the expense, purchases and prices, adds the amounts to the kitchen and ticks the shopping list. The photo is deleted once imported, drafts after a week, card numbers masked. The real-OCR test runs in CI |
| 88 | Implement expiry-date tracking and notifications | |
| 89 | Build recipe recommendations using available ingredients | |
| 90 | Implement meal planning and automatic shopping lists | |
| 91 | Test the complete kitchen workflow | |
