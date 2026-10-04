-- As HH. A day of new activity between the level 0 and the level 1 backup:
-- every household logs this week's meals again and a few expenses.
SET TIMING ON
INSERT INTO food_entries (id, household_id, user_id, entry_date, eaten_at, name, meal, kcal, protein, fiber)
SELECT LOWER(SUBSTR(RAWTOHEX(SYS_GUID()), 1, 26)), household_id, user_id, entry_date, eaten_at + INTERVAL '1' MINUTE,
       name, meal, kcal, protein, fiber
FROM food_entries
WHERE entry_date >= DATE '2026-09-28';

INSERT INTO expenses (id, household_id, user_id, entry_date, amount, category, source, note)
SELECT LOWER(SUBSTR(RAWTOHEX(SYS_GUID()), 1, 26)), id, NULL, DATE '2026-10-04', 12.5, 'eating_out', 'manual', 'Souvlaki'
FROM households;
COMMIT;

SELECT COUNT(*) AS expenses FROM expenses;
SELECT COUNT(*) AS food_entries FROM food_entries;
EXIT
