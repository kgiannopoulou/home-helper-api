# Phase 2: The AI brain

**Goal:** give Home Helper intelligence.
**Deliverable:** you can ask Home Helper "What do I need to buy?", "How much did I spend this month?", "What tasks are overdue?", "Which plants need attention?" and "What should I do today?", and get answers from the household's own data.
**Done:** 2026-11-11. Full description: [assistant.md](assistant.md).

| Day | Task | Result |
|---:|---|---|
| 15 | Choose the AI provider; an AI service abstraction | Claude Opus 5.5 through the official Anthropic PHP SDK. `App\Ai\ChatModel` interface with `ClaudeChatModel` and `OfflineChatModel` (no key needed), chosen by `AI_PROVIDER`. [Why](assistant.md#the-model) |
| 16 | The conversation API endpoint | `/api/households/{h}/assistant/…`: start, ask, read, list, delete, confirm, decline, tools. Errors in the API's format; `503 ai_unavailable` keeps your message |
| 17 | Conversation and message models | `ai_conversations`, `ai_messages` (ordered, with token counts and the model's raw blocks), `ai_pending_actions`. In the ER diagrams |
| 18 | The chat interface in React | `/assistant`: conversation list, chat, suggested questions, "looked at" badges, Confirm/Cancel cards, a safe text renderer |
| 19 | Connect the chat to Laravel | Inertia posts with the session login and CSRF, the same `Assistant` class as the API |
| 20 | History and context | The whole conversation every turn, cached; household context (who, where, today) after the cache breakpoint; Claude's thinking blocks replayed unchanged; confirmations told to the model as events |
| 21 | Rest and review | The review found and fixed: tool-lookup steps shown as empty messages (now one answer per turn), confirming twice returning 200 (now `409 action_closed`), the offline router following only the first topic, appointments labelled as bills. And it found that the AI code was not typed tightly enough for PHPStan; the models it reads now have `@property` docblocks, which also removed 46 old errors from the baseline |
| 22 | A tool registry | `ToolRegistry`: one gate for every call. Tools declare a schema, validation rules, whether they write, their policy and a sentence for the Confirm button |
| 23 | Read-only household tools | `get_household_overview`, `get_today_plan` |
| 24 | Tasks, shopping lists, kitchen | `list_todos`, `list_overdue_chores`, `list_upcoming_events`, `get_shopping_list`, `get_kitchen_inventory`; writes `add_todo`, `complete_todo`, `mark_chore_done`, `add_shopping_item`, `check_off_shopping_item` |
| 25 | Finance and bills | `get_spending_summary`, `list_expenses`, `get_upcoming_bills`. Read only on purpose |
| 26 | Plants, weather, maintenance | `get_plant_care_today`, `list_plant_alerts`, `get_weather_forecast`, `list_home_maintenance`; write `log_plant_care` |
| 27 | Permissions, validation, confirmation | Membership on every call, module policies, ids scoped to the household, writes only as pending actions, re-checked on Confirm, 30-minute expiry, writes can be switched off, rate and daily limits, private conversations. [Details](assistant.md#permissions-validation-and-confirmation) |
| 28 | Real conversations across modules | `ConversationsTest` on the six-month demo household, and `php artisan ai:eval`: nine questions in one conversation, checking the tools used. 9/9 with the offline model; **not yet run against Claude** (no API key where it was built) |

## In numbers

- 20 tools (14 read, 6 write with confirmation), 3 tables, 8 API endpoints, 1 page.
- Pest: 407 → **471** tests (64 new in `tests/Feature/Ai`). Vitest: 18 → **24**.
- PHPStan: new code adds nothing to the baseline, which shrank from 285 to 239.

## To do next

- Run `php artisan ai:eval` against Claude with a real key, and read the answers.
- Stream answers to the page instead of waiting for the whole turn.
- The phone app: a chat screen on the same API.
- More writes, carefully: adding an expense, planning the week.
