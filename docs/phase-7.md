# Phase 7: Kitchen and shopping intelligence

**Goal:** make the kitchen smarter.
**Deliverable:** an intelligent kitchen assistant that helps reduce waste and manage shopping.
How it works: [kitchen.md](kitchen.md).

| Day | Task | Result |
|---:|---|---|
| 85 | Improve food inventory and ingredient tracking | Amounts in base units (g, ml, pieces) parsed from "1,5 kg", "2 x 500g" or "6 αυγά"; the level follows the amount. One ingredient key per food, in English and Greek. Every change is a `kitchen_movements` row (bought, used, cooked, wasted, expired, counted), locked so two phones can't both take the last egg. The Kitchen page, and use/add endpoints for the phone |
| 86 | Add barcode scanning | |
| 87 | Add receipt and grocery OCR | |
| 88 | Implement expiry-date tracking and notifications | |
| 89 | Build recipe recommendations using available ingredients | |
| 90 | Implement meal planning and automatic shopping lists | |
| 91 | Test the complete kitchen workflow | |
