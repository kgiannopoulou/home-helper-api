<?php

namespace App\Briefing;

use App\Models\Household;
use App\Models\RecommendationFeedback;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Personalised recommendations (docs/briefings.md#recommendations): a few
 * concrete next steps, each with the reason it was suggested and a question
 * the person can hand to the assistant.
 *
 * Rules, not a model: every suggestion is explainable and testable. What
 * makes them personal:
 *   - chores: the ones assigned to you, or that you have done most often lately, come first
 *   - feedback: "not now" hides one for a week; a kind of suggestion you keep
 *     dismissing sinks, one you mark useful rises
 *   - the question in `ask` goes to the assistant, which knows the household's
 *     remembered preferences (a vegetarian, a favourite shop)
 */
class Recommender
{
    /**
     * @param  array<string, mixed>  $b  the briefing so far (BriefingService::build)
     * @return list<array{key: string, rule: string, title: string, reason: string, ask: string|null, priority: int}>
     */
    public function recommend(Household $household, User $user, CarbonImmutable $today, array $b): array
    {
        $money = fn (?float $x) => Money::exact((float) $x, $household->currency);
        $candidates = [];
        $add = function (string $rule, string $key, int $priority, string $title, string $reason, ?string $ask = null) use (&$candidates) {
            $candidates[] = ['key' => "{$rule}:{$key}", 'rule' => $rule, 'title' => $title, 'reason' => $reason, 'ask' => $ask, 'priority' => $priority];
        };

        // Plants in danger first: frost, heat, storm
        foreach ($b['plants']['weather_warnings'] ?? [] as $w) {
            $add('plant_weather', $w['name'].':'.$today->toDateString(), 90, "{$w['name']}: {$w['warning']}", 'from the forecast for the next 24 hours', "What should I do about the {$w['name']} and the weather?");
        }

        if (($m = $b['money']) && $m['over_budget'] > 0) {
            $top = $m['top_categories'][0] ?? null;
            $add('budget_pace', $today->format('Y-m'), 80,
                'Slow down on spending: '.$money($m['over_budget']).' over budget at this pace',
                'you have spent '.$money($m['month_to_date']).' by the '.$today->format('jS').($top ? ', most on '.str_replace('_', ' ', $top['category']) : ''),
                'Where is our money going this month compared with last month?');
        }

        $rain = array_values(array_filter($b['plants']['to_water'] ?? [], fn ($p) => $p['skip_for_rain']));
        if ($rain) {
            $add('rain_watering', $today->toDateString(), 70,
                'Skip watering '.self::names(array_column($rain, 'name')).': rain will do it',
                ($b['weather']['precipitation_mm'] ?? '?').' mm of rain forecast');
        }

        $shopping = $b['shopping'];
        $soon = array_values(array_filter($shopping['running_out'] ?? [], fn ($r) => $r['days_left'] <= 3));
        if ($soon) {
            $names = array_column($soon, 'name');
            $add('running_out', implode(',', $names), 65 + ($shopping['today_is_shopping_day'] ? 10 : 0),
                'Add '.self::names($names).' to the shopping list',
                self::runOutReason((int) min(array_column($soon, 'days_left'))),
                'Add '.self::names($names).' to the shopping list.');
        }

        $expiring = array_slice($b['kitchen']['expiring'] ?? [], 0, 4);
        if ($expiring) {
            $names = array_column($expiring, 'name');
            $add('expiring_food', implode(',', $names).':'.$today->toDateString(), 60,
                'Use '.self::names($names).' first',
                'expiring by '.CarbonImmutable::parse(min(array_column($expiring, 'expires_on')))->format('D j M'),
                'Suggest a dinner that uses '.self::names($names).'.');
        }

        foreach (array_slice($b['bills'], 0, 2) as $bill) {
            if (($bill['already_recorded_this_month'] ?? false) || CarbonImmutable::parse($bill['due_on'])->gt($today->addDays(3))) {
                continue;
            }
            $name = $bill['name'] ?? $bill['title'];
            $add('bill_due', $name.':'.$bill['due_on'], 60, "{$name} ".$money($bill['amount']).' is due '.CarbonImmutable::parse($bill['due_on'])->format('l j M'), 'a recurring payment, not recorded yet this month');
        }

        foreach ($b['maintenance']['due'] ?? [] as $a) {
            if ($a['kind'] === 'renewal' || $a['kind'] === 'appointment') {
                $add('upkeep_due', $a['title'].':'.$a['due_on'], $a['overdue'] ? 58 : 50, "{$a['title']} is due ".CarbonImmutable::parse($a['due_on'])->format('j M'), $a['overdue'] ? 'it is overdue' : 'coming up in the next two weeks');
            }
        }

        if ($chore = $this->choreFor($household, $user, $today, $b['tasks']['chores_overdue'])) {
            [$c, $why] = $chore;
            $add('overdue_chore', $c['id'].':'.$c['last_done'], 45 + min(20, (int) $c['days_overdue']),
                "{$c['room']}: {$c['name']} ({$c['days_overdue']} days late)", $why, "Mark \"{$c['name']}\" as done.");
        }

        $outOfStock = array_values(array_filter($b['maintenance']['supplies_low_or_out'] ?? [], fn ($s) => $s['level'] === 'out'));
        if ($outOfStock) {
            $names = array_column($outOfStock, 'name');
            $add('supplies_out', implode(',', $names), 40, 'Buy '.self::names($names), 'marked as run out', 'Add '.self::names($names).' to the shopping list.');
        }

        if ($shopping && $shopping['today_is_shopping_day'] && $shopping['count'] > 0) {
            $add('shopping_day', $today->toDateString(), 35, "It's your usual shopping day: {$shopping['count']} items on the list",
                $shopping['estimated_total'] ? 'about '.$money($shopping['estimated_total']) : 'judging by when you usually shop', 'What do I need to buy?');
        }

        return $this->personalise($household, $user, $today, $candidates);
    }

    /**
     * Feedback: hide what was dismissed, move whole rules up or down.
     *
     * @param  list<array{key: string, rule: string, title: string, reason: string, ask: string|null, priority: int}>  $candidates
     * @return list<array{key: string, rule: string, title: string, reason: string, ask: string|null, priority: int}>
     */
    private function personalise(Household $household, User $user, CarbonImmutable $today, array $candidates): array
    {
        $feedback = RecommendationFeedback::query()->whereBelongsTo($household)->where('user_id', $user->id)
            ->where('updated_at', '>=', $today->subDays(60))->get();

        $hidden = $feedback->filter(fn (RecommendationFeedback $f) => $f->verdict === 'dismissed' && $f->hidden_until?->gte($today))->pluck('key')->all();
        $byRule = $feedback->groupBy('rule')->map(fn ($rows) => min(20, 10 * $rows->where('verdict', 'useful')->count()) - 12 * $rows->where('verdict', 'dismissed')->count());

        $out = [];
        foreach ($candidates as $c) {
            if (in_array($c['key'], $hidden, true)) {
                continue;
            }
            $c['priority'] = max(0, $c['priority'] + (int) ($byRule[$c['rule']] ?? 0));
            $out[] = $c;
        }
        usort($out, fn ($a, $b) => $b['priority'] <=> $a['priority']);

        return array_slice($out, 0, (int) config('homehelper.briefing.max_recommendations'));
    }

    /**
     * The overdue chore to suggest to this person, and why them.
     *
     * @param  list<array<string, mixed>>  $overdue
     * @return array{0: array<string, mixed>, 1: string}|null
     */
    private function choreFor(Household $household, User $user, CarbonImmutable $today, array $overdue): ?array
    {
        if ($overdue === []) {
            return null;
        }

        // How often each overdue chore was done by this person, against everyone, in 90 days
        $history = DB::table('chore_completions')->where('household_id', $household->id)->whereNull('deleted_at')
            ->whereIn('chore_id', array_column($overdue, 'id'))->where('done_at', '>=', $today->subDays(90)->utc())
            ->groupBy('chore_id')->selectRaw('chore_id, sum(user_id = ?) as mine, count(*) as total', [$user->id])
            ->get()->keyBy('chore_id');

        $best = null;
        $bestScore = -1;
        foreach ($overdue as $c) {
            $h = $history->get($c['id']);
            $share = $h && $h->total > 0 ? $h->mine / $h->total : 0;
            $score = ($c['yours'] ? 2 : 0) + $share + min(1, $c['days_overdue'] / 30);
            if ($score > $bestScore) {
                [$best, $bestScore] = [[$c, match (true) {
                    $c['yours'] => 'it is assigned to you',
                    $share >= 0.5 => 'you usually do this one',
                    default => 'the most overdue chore',
                }], $score];
            }
        }

        return $best;
    }

    private static function runOutReason(int $days): string
    {
        return match (true) {
            $days <= 0 => 'by how often you buy it, it has probably run out already',
            $days === 1 => 'by how often you buy it, it runs out tomorrow',
            default => "by how often you buy it, it runs out in {$days} days",
        };
    }

    /** @param list<string> $names "coffee, eggs and milk" */
    private static function names(array $names): string
    {
        $names = array_values(array_unique($names));
        $last = array_pop($names);

        return $names ? implode(', ', $names).' and '.$last : (string) $last;
    }
}
