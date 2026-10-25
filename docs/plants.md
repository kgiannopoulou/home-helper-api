# Plant care

A household adds its plants, and the server answers four questions for each one: what to do today, how much water, whether the weather changes that, and whether an outdoor plant should move. It reuses what the rest of the app already has: household-scoped resources and policies, the scheduler and Redis queue, Expo push, and phone sync.

Plants are their own module, not chores. A watering reminder starts out looking like a chore, but a plant also has a species, a place in or out of the weather, temperature limits, several care schedules and a health history.

## Data

| Table | What it holds |
|---|---|
| `plants` | The plant: name, species, nickname, optional room, `location_type` (`indoor`, `outdoor`, `balcony`, `greenhouse`), light, watering interval and amount, fertilizer and repotting intervals, temperature limits, and whether it minds frost, rain or wind. Also `last_*` / `next_*` dates for watering, fertilizer and repotting, which the server keeps |
| `plant_care_logs` | Every care event: `watered`, `fertilized`, `pruned`, `repotted`, `misted`, `moved`, `inspected` (health notes such as "yellow lower leaves"), with who, when, how much and notes |
| `plant_alerts` | What the hourly check raised: type, severity, title, message, the numbers behind it (JSON), when it was raised, pushed and resolved |
| `households.latitude/longitude` | Where the home is, for the forecast. It belongs to the household, not to each plant, so one forecast covers every plant |

Diagram: [database.md → Plant care](database.md#plant-care). CHECK constraints cover the ranges (1–365 days, 1–100,000 ml, minimum ≤ maximum temperature, both coordinates or neither).

**The server owns the dates.** `last_watered_at` is the latest live `watered` log, and `next_watering_at` is that plus the interval (counted from when the plant was added if it was never watered). They are recalculated whenever a log is added, edited or deleted, from the API or from a phone's sync, and whenever an interval changes. Phones never send them. Two phones that water the same plant offline both send their event, and the later one wins no matter which arrives first. Recalculating doesn't touch the plant's `updated_at`, so it can't beat a real edit in last-write-wins.

## API

All under `/api/households/{household}`, with the same token, membership check and error format as every other module ([api.md](api.md)).

```http
GET|POST             plants                         CRUD, ?sort=next_watering_at
GET|PUT|PATCH|DELETE plants/{id}
GET|POST             plant-care-logs                CRUD (fix or delete a log), ?from=&to= on performed_at
GET|PUT|PATCH|DELETE plant-care-logs/{id}

POST plants/{plant}/water      {amount_ml?, performed_at?, notes?}    amount defaults to the plant's usual one
POST plants/{plant}/fertilize  {performed_at?, notes?}
POST plants/{plant}/repot | prune | mist
POST plants/{plant}/move       {location_type and/or room_id, notes?}  also moves the plant
POST plants/{plant}/inspect    {notes}                                 a health note
GET  plants/{plant}/care-history   ?type=watered                       newest first

GET  plant-alerts              ?status=open|resolved|all  ?plant_id=  ?type=
POST plant-alerts/{id}/resolve                                         dismiss

GET  plant-care/today                                                  what needs attention today
GET  plant-care/plan?from=2026-08-05&to=2026-08-18                     care while you're away

PATCH /api/households/{household}  {latitude, longitude}               owner only
```

A care action answers with the new log and the updated plant, so the phone can show the next date straight away:

```json
{
  "data": { "id": "01…", "plant_id": "01…", "type": "watered", "amount_ml": 350, "performed_at": "2026-10-25T15:30:00+00:00" },
  "plant": { "id": "01…", "name": "Monstera", "last_watered_at": "2026-10-25T15:30:00+00:00", "next_watering_at": "2026-11-01T15:30:00+00:00" }
}
```

`plant-care/today` is the phone's home widget, worked out on the server so the app doesn't repeat the rules:

```json
{
  "date": "2026-10-25",
  "forecast": { "minimum_temperature_c": 6, "minimum_at": "2026-10-26T02:00:00+00:00", "maximum_temperature_c": 17, "precipitation_mm": 0.4, "max_wind_gust_kmh": 31 },
  "watering": [
    { "plant_id": "01…", "name": "Monstera", "amount_ml": 350, "due_at": "…", "overdue_days": 0, "postponed_for_rain": false },
    { "plant_id": "01…", "name": "Pothos", "amount_ml": 250, "due_at": "…", "overdue_days": 2, "postponed_for_rain": false }
  ],
  "care": [{ "plant_id": "01…", "name": "Monstera", "type": "fertilizing_due", "title": "🌿 Monstera is due for fertilizer", "message": "Last fertilized 30 days ago." }],
  "weather_warnings": [{ "plant_id": "01…", "name": "Basil", "type": "move_inside", "severity": "warning", "title": "⚠️ Move Basil inside", "message": "It may fall to 6°C around 05:00. Basil's minimum is 10°C." }],
  "upcoming": [{ "plant_id": "01…", "name": "Aloe", "type": "water", "due_on": "2026-10-29" }]
}
```

`plant-care/plan` lists each plant's waterings and fertilizer between two days (at most 92), as if done on schedule. Care that is already overdue lands on the first day. Outdoor plants come with `check_weather: true`, because no plan can know the forecast for August.

## The hourly check

`CheckPlantCare` (`app/Jobs`) runs every hour and fans out one queued job per household, like the other [scheduled jobs](jobs.md). It doesn't make one schedule per plant: one evaluator reads all of a household's plants.

```text
scheduler (hourly) → CheckPlantCare → one job per household
    → one forecast (cached 30 min) → PlantCareService::warnings() for each plant
    → plant_alerts: create the new ones, resolve the stale ones → one Expo push
```

The job coordinates, `PlantCareService` (`app/Plants`) decides, and `ExpoPush` sends. The rules live only in the service, which the `today` endpoint uses too.

**No spam.** An open alert of the same type for the same plant means it was already sent, so nothing is sent again until it is resolved. Watering resolves the watering reminders at once. Other alerts resolve on the next check once their reason is gone (the frost passed, the plant moved inside). A weather warning isn't raised again within 12 hours of the last one, so a household that dealt with it isn't told again an hour later. New alerts in one run go out as **one** push, most severe first (*"⚠️ 2 plant reminders · Move Basil inside · Monstera needs water"*). Repotting is recorded but never pushed.

**Quiet hours.** Reminders that can wait (watering, fertilizer, repotting) are raised only between 08:00 and 21:00 at home. Weather warnings go out whenever they appear.

| Alert | Severity | When |
|---|---|---|
| `watering_due` | info | Due today: *"🌿 Monstera needs water · Give it about 350 ml today."* |
| `watering_overdue` | warning | 2 or more days late |
| `watering_postponed` | recommendation | Due, outdoors, and ≥ 5 mm of rain forecast: *"12 mm of rain is expected in the next 24 hours, so Basil probably doesn't need its usual 150 ml."* |
| `fertilizing_due` | info | Its own interval since the last `fertilized` log |
| `repotting_due` | info | Months since the last `repotted` log. Not pushed |
| `frost_warning` | urgent | Frost-sensitive, outdoors, low ≤ 2 °C |
| `move_inside` | warning | Outdoors, low below the plant's minimum: *"It may fall to 7°C around 04:00. Basil's minimum is 10°C."* |
| `move_to_shade` | warning | Outdoors, high above the plant's maximum |
| `heavy_rain` | warning | Rain-sensitive, outdoors, ≥ 20 mm forecast |
| `strong_wind` | warning | Wind-sensitive, outdoors, gusts ≥ 50 km/h |

"Outdoors" means `outdoor` or `balcony`. Indoor and greenhouse plants never get weather alerts. Thresholds and hours are in `config/homehelper.php` → `plants`.

**Watering amount.** The plant's usual amount, 15% more when an outdoor plant has a day of 30 °C or more, 15% less at 15 °C or below, rounded to 10 ml.

## Weather

```text
PlantCareService → WeatherProvider (interface) → OpenMeteoWeatherProvider → api.open-meteo.com
```

The care rules only know the `WeatherProvider` interface (`app/Weather`), so the vendor can change without touching them. Open-Meteo is free and needs no key. It gives the hourly temperature, rain and gusts for the next 24 hours, which are reduced to the low (and when), the high (and when), total rain and the strongest gust. The forecast is fetched once per place and cached for 30 minutes: a home with 40 plants makes one request, and none if it has no outdoor plants. Without a location, or if the provider fails, the weather rules are skipped and the care reminders still go out. Open weather alerts are kept, not resolved, because an unknown forecast doesn't mean the frost has passed.

## Sync

`plants` and `plant_care_logs` are sync collections ([sync.md](sync.md)). The phone syncs care **events**. A plant has no natural key, because two basils added on two phones may really be two basils. Alerts are written only by the server, so they aren't synced: phones get the push and read `plant-alerts`.

## Tests

`tests/Feature/Plants` and two more rows in the module sweep (`ModulesTest`), with a `FakeWeatherProvider` (`tests/Support`) instead of real requests:

- **API:** first due date, watering logs the usual amount and moves the dates, phone time zones, no care in the future, an older log doesn't move dates back, deleting a mistaken log does, interval changes, dates can't be set from the body, fertilizer and repotting schedules, moving, the health timeline, another household's plant and room, validation, and the CHECK constraint as a safety net.
- **Alerts:** open by default, history, dismissing, other households' alerts, a deleted plant's alerts.
- **Job:** due → one alert and one push, not due → nothing, hourly runs don't repeat, watering resolves, overdue, due turning into overdue, quiet hours, fertilizer, repotting not pushed, one grouped push.
- **Weather:** indoor plants ignore it (and no forecast is fetched), below minimum, frost, heat, rain postpones watering outdoors only, rain and wind only for sensitive plants, resolve and cooldown, moving inside resolves, one forecast per household, no location, provider down.
- **Today, plan, location, sync:** today's amounts on a hot day, the away plan, plan ranges, owner-only location, two phones' waterings in either order, care dates from a phone are ignored, and Open-Meteo's response parsed into one forecast.

## Not built yet

These come next, in roughly this order:

- **Phone screens** in [personal-home-helper](https://github.com/kgiannopoulou/personal-home-helper) for plants, care buttons and the today widget, plus a **dashboard** card and week-planner entries here.
- **Notification preferences** per member (watering yes, fertilizer no, urgent always) and **care assignment** to one member.
- **Species profiles** (`plant_species`) that prefill intervals, light and temperatures, with **pet toxicity** warnings.
- **Photos** and a growth timeline.
- **Adaptive care:** learn the real interval from the logs, like `LearnChoreFrequencies` does for chores (*"you water this Monstera every 8–9 days, not 7"*), with seasonal averages.
- Humidity, soil-moisture sensors, and a QR code on each pot.
