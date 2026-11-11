<?php

namespace App\Ai\Providers;

use App\Ai\ChatModel;
use App\Ai\ChatReply;
use App\Ai\ChatRequest;
use App\Models\AiMessage;
use Illuminate\Support\Str;

/**
 * No language model: keywords pick the tools, and their results are written
 * out with fixed templates. For running the assistant locally without an API
 * key (AI_PROVIDER=offline) and for demos. It goes through exactly the same
 * loop, registry, permissions and confirmations as Claude does, but it only
 * understands the common questions and doesn't remember context.
 */
class OfflineChatModel implements ChatModel
{
    /** keyword pattern → tools to call */
    private const ROUTES = [
        '/\b(brief me|briefing|good morning|focus on|suggestions?|recommend\w*)\b/i' => ['get_daily_briefing'],
        '/\b(today|day|plan|should i do)\b/i' => ['get_today_plan'],
        '/\b(buy|shopping|groceries|shop)\b/i' => ['get_shopping_list'],
        '/\b(spend|spent|spending|money|budget|cost)\b/i' => ['get_spending_summary'],
        '/\b(overdue|chores?|tasks?|to-?dos?)\b/i' => ['list_overdue_chores', 'list_todos'],
        '/\b(plants?|water|watering)\b/i' => ['get_plant_care_today'],
        '/\b(bills?|rent|pay)\b/i' => ['get_upcoming_bills'],
        '/\b(weather|rain|forecast|frost)\b/i' => ['get_weather_forecast'],
        '/\b(kitchen|fridge|expir\w*|running low|run out)\b/i' => ['get_kitchen_inventory'],
        '/\b(calendar|events?|appointments?)\b/i' => ['list_upcoming_events'],
        '/\b(renewals?|maintenance|supplies)\b/i' => ['list_home_maintenance'],
    ];

    public function name(): string
    {
        return 'offline';
    }

    public function reply(ChatRequest $request): ChatReply
    {
        $last = $request->transcript[array_key_last($request->transcript) ?? 0] ?? null;
        if ($last instanceof AiMessage && $last->role === 'tool_results') {
            return ChatReply::text($this->summarize($last->content['results'] ?? []));
        }

        $text = $request->lastUserText();

        if (preg_match('/^\s*(?:please\s+)?add\s+(.+?)\s+to\s+(?:the\s+|my\s+)?(?:shopping\s+)?list\b/i', $text, $m)) {
            return $this->call([['add_shopping_item', ['name' => Str::ucfirst(trim($m[1], " \"'")), 'category' => 'food']]]);
        }

        // Every topic the question touches ("bills ... budget" → bills and spending), at most 4 tools
        $tools = [];
        foreach (self::ROUTES as $pattern => $routeTools) {
            if (preg_match($pattern, $text)) {
                array_push($tools, ...$routeTools);
            }
        }
        // Memory (Phase 3): the past, written-down things, remembered facts
        if (preg_match('/^\s*(?:please\s+)?remember\s+(?:that\s+)?(.+?)[.!]?\s*$/is', $text, $m)) {
            $kind = preg_match('/\b(prefers?|likes?|loves?|hates?|don\'t|doesn\'t|never|always|vegetarian|vegan|allergic)\b/i', $m[1]) ? 'preference'
                : (preg_match('/\b(every|each|on (mondays|tuesdays|wednesdays|thursdays|fridays|saturdays|sundays))\b/i', $m[1]) ? 'routine' : 'fact');

            return $this->call([['remember_fact', ['kind' => $kind, 'content' => Str::ucfirst(trim($m[1]))]]]);
        }
        $memory = [];
        if (preg_match('/\b(when did|last time|how long ago|who (watered|cleaned|did|paid|bought)|history|happened)\b/i', $text)) {
            $memory[] = ['get_activity_timeline', array_filter(['from' => now()->subYear()->toDateString(), 'search' => self::keyword($text)])];
        }
        if (preg_match('/\b(last month|last week|compare\w*|how did .+ go|summary|summaries)\b/i', $text)) {
            $memory[] = ['get_household_summaries', ['period' => preg_match('/\bweek/i', $text) ? 'week' : 'month']];
        }
        if (preg_match('/\b(decide\w*|notes?|manual|instructions?|how do (i|we)|told you|documents?|warranty|remember\w*)\b/i', $text)) {
            $memory[] = ['search_household_memory', ['query' => $text]];
        }
        if ($memory !== []) {
            return $this->call($memory);
        }

        if ($tools !== []) {
            return $this->call(array_map(fn (string $t) => [$t, []], array_slice(array_values(array_unique($tools)), 0, 4)));
        }

        return ChatReply::text("I'm running without a language model, so I only understand the common questions: what to buy, what you spent this month, overdue chores and to-dos, which plants need attention, bills, the weather, and what to do today. You can also say \"add milk to the list\".");
    }

    /** The word a timeline search should match: a Name in the sentence, else its longest word */
    private static function keyword(string $text): ?string
    {
        if (preg_match_all('/(?<!^)(?<![.?!]\s)\b([A-Z][\p{L}-]{2,})/u', $text, $m)) {
            return end($m[1]);
        }
        $words = array_filter(preg_split('/[^\p{L}]+/u', $text) ?: [], fn (string $w) => mb_strlen($w) > 3
            && ! in_array(mb_strtolower($w), ['when', 'last', 'time', 'what', 'who', 'did', 'have', 'were', 'this', 'that', 'with', 'from', 'happened', 'history', 'long', 'ago'], true));
        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $words[0] ?? null;
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $calls */
    private function call(array $calls): ChatReply
    {
        return new ChatReply(
            blocks: array_map(fn (array $c) => ['type' => 'tool_call', 'id' => 'offline_'.Str::lower(Str::random(12)), 'name' => $c[0], 'input' => $c[1]], $calls),
            stopReason: 'tool_use',
            model: 'offline',
        );
    }

    /** @param list<array{name: string, content: array<string, mixed>, is_error: bool}> $results */
    private function summarize(array $results): string
    {
        $parts = [];
        foreach ($results as $r) {
            $c = $r['content'];
            if ($r['is_error']) {
                $parts[] = 'That didn\'t work: '.($c['error'] ?? 'unknown error').(isset($c['fields']) ? ' '.implode(' ', array_merge(...array_values(array_map(fn ($e) => (array) $e, $c['fields'])))) : '');

                continue;
            }
            $parts[] = match ($r['name']) {
                'get_shopping_list' => $this->list('To buy', array_map(fn ($i) => $i['name'].($i['quantity'] ? " ({$i['quantity']})" : ''), $c['items']), 'The shopping list is empty.')
                    .($c['suggested_not_on_list'] ? "\n".$this->list('Running out soon, not on the list', array_column($c['suggested_not_on_list'], 'name')) : ''),
                'get_spending_summary' => "Spent so far in {$c['month']}: ".number_format((float) $c['total'], 2)." {$c['currency']}"
                    .($c['budget'] ? ' of a '.number_format((float) $c['budget'], 2).' budget' : '').".\n"
                    .$this->list('Biggest categories', array_map(fn ($x) => "{$x['category']}: ".number_format((float) $x['total'], 2), array_slice($c['categories'], 0, 4))),
                'list_overdue_chores' => $this->list('Overdue chores', array_map(fn ($x) => "{$x['room']}: {$x['name']} ({$x['days_overdue']} days late)", $c['overdue']), 'No chores are overdue.'),
                'list_todos' => $this->list('Open to-dos', array_map(fn ($x) => $x['title'].($x['overdue'] ? ' (overdue)' : ''), array_slice($c['todos'], 0, 8)), 'No open to-dos.'),
                'get_plant_care_today' => $this->list('Plants to water', array_map(fn ($x) => $x['name'].($x['amount_ml'] ? " ({$x['amount_ml']} ml)" : ''), $c['watering']), 'No plants need water today.')
                    .($c['weather_warnings'] ? "\n".$this->list('Weather warnings', array_map(fn ($x) => "{$x['name']}: {$x['title']}", $c['weather_warnings'])) : ''),
                'get_today_plan' => $this->todaySummary($c),
                'get_upcoming_bills' => $this->list('Bills coming up', array_map(fn ($x) => "{$x['name']}: ".number_format((float) $x['amount'], 2)." on {$x['due_on']}", $c['recurring']), 'No bills in the next 30 days.'),
                'get_weather_forecast' => $c['available'] ? "Next 24 hours: {$c['next_24_hours']['minimum_temperature_c']}–{$c['next_24_hours']['maximum_temperature_c']} °C, {$c['next_24_hours']['precipitation_mm']} mm of rain." : $c['reason'],
                'get_kitchen_inventory' => $this->list('Low or empty', array_column($c['low_or_empty'], 'name'), 'Nothing in the kitchen is low.'),
                'list_upcoming_events' => $this->list('Coming up', array_map(fn ($x) => "{$x['starts']}: {$x['title']}", $c['events']), 'Nothing on the calendar this week.'),
                'list_home_maintenance' => $this->list('Coming up', array_map(fn ($x) => "{$x['title']} ({$x['due_on']})", $c['admin']), 'No renewals or appointments coming up.'),
                'add_shopping_item', 'remember_fact', 'save_note', 'forget_fact' => 'I can do that: '.$c['proposed'].'. Confirm it below.',
                'get_daily_briefing' => $c['briefing'],
                'search_household_memory' => $c['hits']
                    ? $this->list('From your notes and memory', array_map(fn ($h) => $h['title'].($h['about_date'] ? " ({$h['about_date']})" : '').': '.Str::limit(preg_replace('/\s+/', ' ', $h['passage']) ?? '', 400), array_slice($c['hits'], 0, 3)))
                    : 'I found nothing about that in your notes or memory.',
                'get_activity_timeline' => $this->list('Most recent first', array_map(fn ($e) => "{$e['date']}".($e['time'] ? " {$e['time']}" : '').": {$e['title']}".($e['who'] ? " ({$e['who']})" : ''), array_slice($c['entries'], 0, 6)), 'Nothing like that in the household\'s history.'),
                'get_household_summaries' => $c['summaries'] ? implode('

', array_map(fn ($x) => $x['text'], array_slice($c['summaries'], 0, 2))) : $c['note'],
                default => 'Done.',
            };
        }

        return implode("\n\n", $parts);
    }

    /** @param array<string, mixed> $c */
    private function todaySummary(array $c): string
    {
        $lines = [
            ...array_map(fn ($x) => "Chore: {$x['room']}: {$x['name']}", $c['chores_due_today']),
            ...array_map(fn ($x) => "Overdue chore: {$x['room']}: {$x['name']}", array_slice($c['chores_overdue'], 0, 3)),
            ...array_map(fn ($x) => "{$x['starts']}: {$x['title']}", $c['events_today']),
            ...array_map(fn ($x) => "To-do: {$x['title']}", $c['todos_due']),
            ...array_map(fn ($x) => "Water {$x['name']}", $c['plants']['to_water']),
            ...array_map(fn ($x) => 'Due soon: '.($x['name'] ?? $x['title']), $c['bills_and_admin_next_3_days']),
        ];
        if ($c['shopping']['today_is_shopping_day']) {
            $lines[] = "It's your usual shopping day ({$c['shopping']['items_on_list']} items on the list)";
        }

        return $this->list("Today, {$c['weekday']}", $lines, 'Nothing is due today.');
    }

    /** @param array<string> $items */
    private function list(string $title, array $items, string $empty = ''): string
    {
        return $items === [] ? $empty : "{$title}:\n".implode("\n", array_map(fn ($i) => "- {$i}", $items));
    }
}
