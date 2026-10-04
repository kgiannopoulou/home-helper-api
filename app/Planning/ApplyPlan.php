<?php

namespace App\Planning;

use App\Models\Event;
use App\Models\Household;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts the ticked items of a week plan in the household's calendar, all or nothing.
 *
 * The browser only sends the keys of what it ticked. The plan is worked out again
 * here, so times can't be made up in the browser; if the plan changed since the page
 * was opened (a chore was done, an event added), the keys no longer match and nothing
 * is saved. Everything is written in one transaction: if one item fails, none are kept.
 * Applying twice doesn't add the same event twice.
 */
class ApplyPlan
{
    /**
     * @param  list<string>  $keys
     * @return array{added: int, skipped: int}
     */
    public function __invoke(Household $household, User $user, CarbonImmutable $weekStart, array $keys): array
    {
        $plan = (new WeekPlanner($household, $weekStart))->plan();
        $items = collect($plan['items'])->keyBy('key');

        $missing = array_values(array_diff($keys, $items->keys()->all()));
        if ($missing) {
            throw ValidationException::withMessages([
                'keys' => 'The plan changed since you opened it. Reload the page to see the new one.',
            ]);
        }

        $tz = (string) config('homehelper.timezone');

        return DB::transaction(function () use ($household, $user, $keys, $items, $tz) {
            $added = 0;
            $skipped = 0;
            foreach ($keys as $key) {
                $item = $items[$key];
                $title = WeekPlanner::EMOJI[$item['kind']].' '.$item['title'];
                $startsAt = CarbonImmutable::parse("{$item['date']} {$item['start']}", $tz)->utc();
                $endsAt = CarbonImmutable::parse("{$item['date']} {$item['end']}", $tz)->utc();

                // Lock the matching rows, so two clicks at once can't both add it
                $exists = $household->events()->where('title', $title)->where('starts_at', $startsAt)->lockForUpdate()->exists();
                if ($exists) {
                    $skipped++;

                    continue;
                }
                $household->events()->create([
                    'user_id' => $user->id,
                    'title' => $title,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'all_day' => false,
                ]);
                // A planned to-do is now due that day
                if (str_starts_with($key, 'todo:')) {
                    $household->todos()->whereKey(substr($key, 5))->firstOrFail()->update(['due_on' => $item['date']]);
                }
                $added++;
            }

            return ['added' => $added, 'skipped' => $skipped];
        });
    }
}
