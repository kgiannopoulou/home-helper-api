# Web dashboard

The browser side of the same Laravel app: Inertia pages in React and TypeScript, built on the starter kit's layout and components. A controller runs the Phase 3 SQL queries and hands the rows to the page as props, so there is no second API to keep in step with the phone.

```php
// app/Http/Controllers/Web/SpendingController.php
return Inertia::render('spending', [
    'spending' => (new WeeklySpendingQuery($household, $today, $weeks))->get(),
    'forecast' => (new BudgetForecastQuery($household, $today))->get(),
]);
```

| Page | What it shows | Where the numbers come from |
|---|---|---|
| **Dashboard** | This week's spending, the next shopping day, overdue chores, the month's forecast | `WeeklySpendingQuery`, `ShoppingDayQuery`, `OverdueChoresQuery`, `BudgetForecastQuery` |
| **Spending** | Spending per week stacked by category (4, 8, 12 or 26 weeks), the month's forecast against the budget | `WeeklySpendingQuery`, `BudgetForecastQuery` |
| **Health** | Your sleep, steps and workouts per week, as three charts (hours, steps and minutes don't share an axis) | `HealthWeeksQuery`: a recursive CTE makes the weeks, so an empty week is still on the chart. Only your own rows |
| **Chores** | Overdue chores, chores that keep slipping and a better frequency, a fair-share chart of minutes per member | `OverdueChoresQuery`, `SlippingChoresQuery`, `FairShareQuery` (`SUM() OVER ()` for each share) |
| **Week planner** | Next week's chores, meals and errands placed in free time around the calendar, with checkboxes and **Apply** | `app/Planning/WeekPlanner.php` |
| **Household** | Members, waiting invites, an invite form (owner), switching household, starting a new one | the same `InviteMember` action as the API |

- **The same household as on the phone.** The dashboard uses the normal session login with the same account. It shows your first household (or the one you switched to, kept in the session). The phone sends the household in every URL instead. A household you aren't a member of is 403, like the API.
- **The week planner.** Chores fall on the day they come due (overdue ones on Monday). Meals use up what expires that week, the evening before it does. Errands are open to-dos and life admin due by the end of the week. Free time is the evening on weekdays and the day at weekends, minus calendar events, and an all-day event takes the whole day. An item that doesn't fit moves to the next day with room, then an earlier one. What fits nowhere is listed.
- **Apply is one transaction.** The browser sends only the keys of the ticked items (`chore:{id}:{date}`, `meal:{date}`, `todo:{id}`). The server works the plan out again, so times can't be made up in the browser. If the plan changed since the page opened, nothing is saved and you're asked to reload. Each item becomes a calendar event, which the phones get on their next sync, and a planned to-do gets that day as its due date. If one write fails, the whole transaction is rolled back. Applying twice doesn't add an event twice, and an applied item keeps its time in the plan instead of becoming busy time to plan around.
- **Forms** use Inertia's `useForm` (Apply, invites, a new household), and the page state uses React hooks (`useState` for the meal, chore and errand filters).
- **Charts** (Recharts) use colours checked for colour-blind separation in light and dark mode. Each spending category always has the same colour, and rarer categories share a grey "Other". Every chart has **Show as table** with the same numbers, and there is never a second y-axis.

Tests: `tests/Feature/Web` checks each page's props (`assertInertia`), membership and switching, invites, and the planner's rules: free time, events, all-day events, meals, overdue chores, what doesn't fit, a daily chore moving off a full day. It also tests Apply: times at home, twice, a stale plan, another household's keys, and the rollback when the second write fails. In the browser, `npm test` runs Vitest with React Testing Library (`resources/js/test`): the planner's ticks and what Apply sends, the spending page's forecast states and its table, and the chart helpers.

