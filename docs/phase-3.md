# Phase 3: Memory and household intelligence

**Goal:** make the assistant understand the household.
**Deliverable:** Home Helper remembers useful information and understands the current state of the household.
**Done:** 2026-11-11. Full description: [memory.md](memory.md) and [briefings.md](briefings.md).

| Day | Task | Result |
|---:|---|---|
| 29 | Design short-term and long-term memory | [The design](memory.md#the-design-day-29): the conversation (verbatim + summary), facts always in the context, notes/documents/summaries searched, history read from the modules. Six tables and three columns in one migration; privacy, confirmation and "typed text is data" rules |
| 30 | Conversation summaries | `ConversationMemory`: a long conversation is sent as summary + newest messages, cut only before a question so tool calls keep their results; quiet conversations summarised hourly into private long-term memory. Claude at low effort writes them, or an extractive summariser offline. [Details](memory.md#conversation-summaries) |
| 31 | Household facts and preferences | `household_facts` (fact, preference, routine; about a member; private), API and page, in every assistant context. `remember_fact` and `forget_fact`, confirmed like every write |
| 32 | Document and note storage | `notes`: typed notes, and `.txt`/`.md`/`.pdf` uploads with their text extracted (`smalot/pdfparser`), the file kept for download. `save_note`, `read_note` |
| 33 | Semantic search and embeddings | `Embedder` interface: Voyage AI `voyage-4` for real use, a hashing embedder (stems, synonyms, trigrams) for tests and offline. `memory_chunks` with packed float32 vectors and a FULLTEXT index; hybrid search merged by reciprocal rank fusion. [Details](memory.md#search) |
| 34 | Connect the knowledge base to the assistant | `search_household_memory`; facts and the conversation summary in the context; a memory section in the instructions |
| 35 | Rest and review | The review found and fixed: rows inserted with `household_id` dropped by mass assignment (feedback, briefings, summaries now set it explicitly); the timeline breaking when its first branch wasn't selected (a row-less first branch now names the columns); a summary job dispatched inside the turn's lock never running (now dispatched after the lock is released); a Phase 2 bug where a turn's "proposed changes" also listed the previous turn's when both fell in the same second (the filter compared whole seconds; now milliseconds); and a date-dependent demo: on some days the demo's slipping chore wasn't overdue, which failed two Phase 2 tests (the seeder now keeps it overdue without changing any other data) |
| 36 | A household activity timeline | `TimelineQuery`: one `UNION ALL` over ten modules, home-day bounds, kinds and search; API, page and `get_activity_timeline`. [Details](memory.md#activity-timeline) |
| 37 | Scheduled household summaries | `HouseholdDigest` + `WeeklyDigest` (Mondays 05:00) and `MonthlyDigest` (the 1st, 05:30), into long-term memory; `get_household_summaries`; `memory:reindex --digests=N` writes the past |
| 38 | The daily briefing service | `BriefingService`, through the assistant's own read tools; `DailyBriefing` at 07:00 saves and pushes each member's |
| 39 | Weather, calendar, tasks and shopping in briefings | Sections for each; chores marked "yours" |
| 40 | Expenses, bills, plants and maintenance | Money (yesterday, month, pace), bills in 7 days, plants (skip for rain), renewals and supplies |
| 41 | Personalised recommendations | `Recommender`: ten rules with reasons and a question for the assistant; chores go to who they belong to; "not now" and "useful" move rules up or down per person. [Details](briefings.md#recommendations) |
| 42 | Can it answer from history? | `HistoricalQuestionsTest` on the six-month demo household: "when did we last descale the kettle?", "who watered the Monstera last?", "what did we decide about the summer holiday?", "how do I fix low boiler pressure?", "how did last month go?", "remember that…" and the next conversation knowing it, and no invented answer when the notes are silent. Each answer is checked against the database. `ai:eval` gained six memory questions: 15/15 with the offline model; **not yet run against Claude** |

## In numbers

- 8 new tools (5 read, 3 write with confirmation): 28 in all. 6 new tables, 3 new columns, 13 API endpoints, 2 pages, 5 scheduled jobs.
- Pest: 471 → **516** tests (including `tests/Integration` for FULLTEXT on committed rows). Vitest: 24 → **30**.

## Not done yet

- Run `php artisan ai:eval` against Claude, and `memory:reindex` with `MEMORY_EMBEDDER=voyage` and a real key.
- The phone app: the briefing as a screen and a notification that opens it; facts and notes.
- OCR for scanned PDFs; other document formats (Word, images).
- Facts the assistant could suggest from patterns ("you always shop on Saturdays") instead of only from what people say.
