# Briefings and recommendations

Phase 3, days 37–41 ([phase-3.md](phase-3.md)). Every morning each member gets a briefing of their day, from every part of the household, with a few personalised suggestions.

## The daily briefing

`App\Briefing\BriefingService::build(household, member, day)`:

| Section | From | Personal? |
|---|---|---|
| Weather | `get_weather_forecast` (one cached forecast per household) | |
| Calendar | today's events and tomorrow's | |
| Tasks | to-dos due or overdue, chores due today, the 5 most overdue chores | chores assigned to you are marked "yours" |
| Shopping | items on the list and their estimated total, the usual shopping day, what is running out and isn't on the list, food to use in the next two days | |
| Money | spent yesterday, this month so far against the budget, the month's forecast at this pace | |
| Bills | recurring bills and one-off bills or renewals in the next 7 days | |
| Plants | to water (or to skip because rain is coming), other care, weather warnings | |
| Home upkeep | renewals and appointments in the next 14 days, supplies low or out | |
| Recommendations | below | yes |

It reads through **the assistant's own read tools** (`GetTodayPlan`, `GetShoppingList`, `GetSpendingSummary`…), so the briefing, the assistant and the dashboard agree on every number. A section whose source fails (the weather service is down) is left out and listed under "Not available right now"; nothing is guessed.

The headline is the push notification:

> 2 things due · Dentist at 10:30 · Internet due Fri 13 · 9–17 °C, rain

`DailyBriefing` runs at `BRIEFING_TIME` (07:00 at home), one queued job per household: for each member it builds the briefing, saves it in `briefings` (one per member per day; the stored one is what was sent) and pushes the headline to their phones, opening `/briefing`.

## Recommendations

`App\Briefing\Recommender`: rules, not a model, so every suggestion has a reason and can be tested. Each has a stable `key`, the `rule` it came from, a `title`, a `reason` and usually a question to `ask` the assistant.

| Rule | When | Priority |
|---|---|---:|
| `plant_weather` | frost, heat, storm or heavy rain for a plant | 90 |
| `budget_pace` | the month's forecast is over the budget | 80 |
| `rain_watering` | a plant due for water will be watered by the rain | 70 |
| `running_out` | something you buy regularly runs out within 3 days and isn't on the list (+10 on shopping day) | 65 |
| `expiring_food` | food expiring in the next two days | 60 |
| `bill_due` | a bill in the next 3 days not yet recorded | 60 |
| `upkeep_due` | a renewal or appointment in the next 14 days | 50 (58 overdue) |
| `overdue_chore` | the overdue chore for **you** (below) | 45 + days late, up to 65 |
| `supplies_out` | a supply marked as run out | 40 |
| `shopping_day` | today is the usual shopping day and the list isn't empty | 35 |

What makes them personal:

- **Chores** go to the person they belong to: assigned to you first, then the ones you did most in the last 90 days ("you usually do this one"), then the most overdue.
- **Feedback.** "Not now" hides that recommendation for 7 days. Each "not now" on a rule in the last 60 days lowers that rule by 12 for you; each "useful" raises it by 10 (up to 20). Someone who always dismisses the budget warning stops seeing it first; nobody else is affected.
- **Asking the assistant.** "Suggest a dinner that uses spinach" goes to the assistant, which has the household's remembered preferences in its context (a vegetarian in the house).

The top 5 are shown.

## API

Under `/api/households/{household}`, for the signed-in member:

| Method and path | What |
|---|---|
| `GET briefing` | Today's briefing, built now (`sent_at: null`); `?date=` an earlier day's, as it was sent (404 if none) |
| `GET recommendations` | Just the recommendations |
| `POST recommendations/feedback` | `{key, verdict: useful \| dismissed}` |
| `GET timeline`, `GET summaries` | [memory.md#api](memory.md#api) |

The assistant reads the same briefing with `get_daily_briefing` ("brief me", "what should I focus on today?").

## The Today page

`/briefing`: the suggestions with **Ask the assistant**, 👍 and **Not now**; the briefing in sections (empty ones left out); the last two weeks of the timeline, by day, filtered by kind or a word; and the latest weekly and monthly summaries.
