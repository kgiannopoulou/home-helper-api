<?php

namespace App\Console\Commands;

use App\Ai\AiUnavailable;
use App\Ai\Assistant;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * php artisan ai:eval — asks the assistant the questions it is built for, on a
 * real household, and checks that each answer came from the right tools
 * (docs/assistant.md#evaluating-it). With AI_PROVIDER=claude every question is
 * a paid API call: about 11 questions, a few cents at medium effort.
 *
 * The conversation is kept, so it can be read afterwards on /assistant.
 */
class AiEval extends Command
{
    protected $signature = 'ai:eval
        {--email=demo@homehelper.test : whose household and permissions to use}
        {--provider= : claude or offline (default: AI_PROVIDER)}';

    protected $description = 'Ask the assistant the deliverable questions and check which tools it used';

    /**
     * One conversation, in order: the five deliverable questions, then turns
     * that need the history and more than one module.
     * question => tools that must be among those it called (any one of a group)
     *
     * @var list<array{0: string, 1: list<list<string>>}>
     */
    public const SCRIPT = [
        ['What do I need to buy?', [['get_shopping_list']]],
        ['How much did I spend this month?', [['get_spending_summary']]],
        ['What tasks are overdue?', [['list_overdue_chores'], ['list_todos', 'get_today_plan']]],
        ['Which plants need attention?', [['get_plant_care_today', 'list_plant_alerts']]],
        ['What should I do today?', [['get_today_plan']]],
        ['Is the weather going to be a problem for the balcony plants?', [['get_weather_forecast', 'get_plant_care_today']]],
        ['Are any bills due before the end of the month, and will we stay within budget?', [['get_upcoming_bills'], ['get_spending_summary']]],
        ['Is anything in the kitchen running low that isn\'t on the list yet?', [['get_kitchen_inventory', 'get_shopping_list']]],
        ['Add oat milk to the shopping list.', [['add_shopping_item']]],
        // Phase 3: memory and history (day 42)
        ['When did we last descale the kettle?', [['get_activity_timeline', 'search_household_memory']]],
        ['What did we decide about the summer holiday?', [['search_household_memory']]],
        ['How do I fix low pressure on the boiler?', [['search_household_memory', 'read_note']]],
        ['How did last month go compared with the month before?', [['get_household_summaries', 'get_spending_summary']]],
        ['Remember that the plumber comes on Thursdays.', [['remember_fact']]],
        ['Brief me for today.', [['get_daily_briefing', 'get_today_plan']]],
    ];

    public function handle(Assistant $assistant): int
    {
        if ($provider = $this->option('provider')) {
            config(['homehelper.ai.provider' => $provider]);
            $assistant = app(Assistant::class);
        }

        $user = User::where('email', $this->option('email'))->first();
        $household = $user?->households()->orderBy('name')->first();
        if (! $household) {
            $this->error('No such user, or they have no household. Seed the demo: php artisan migrate:fresh --seed');

            return self::FAILURE;
        }

        $conversation = $assistant->start($household, $user);
        $conversation->update(['title' => 'ai:eval '.now()->format('Y-m-d H:i')]);
        $this->info('Provider: '.config('homehelper.ai.provider')." · household: {$household->name} · conversation: {$conversation->id}");

        $passed = 0;
        $tokens = ['input' => 0, 'output' => 0, 'cache_read' => 0];
        foreach (self::SCRIPT as $i => [$question, $expected]) {
            $this->newLine();
            $this->line('<fg=cyan>'.($i + 1).". {$question}</>");
            $started = microtime(true);

            try {
                $turn = $assistant->send($conversation, $user, $question);
            } catch (AiUnavailable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $assistantMessages = array_filter($turn['messages'], fn (AiMessage $m) => $m->role === 'assistant');
            $used = array_values(array_unique(array_merge(...array_map(fn (AiMessage $m) => array_column($m->toolCalls(), 'name'), $assistantMessages) ?: [[]])));
            $answer = trim(implode("\n", array_map(fn (AiMessage $m) => $m->text(), $assistantMessages)));
            foreach ($assistantMessages as $m) {
                $tokens['input'] += (int) $m->input_tokens;
                $tokens['output'] += (int) $m->output_tokens;
                $tokens['cache_read'] += (int) $m->cache_read_tokens;
            }

            $missing = array_filter($expected, fn (array $group) => ! array_intersect($group, $used));
            $ok = $missing === [] && $answer !== '';
            $passed += (int) $ok;

            $this->line($answer === '' ? '<fg=red>(no answer)</>' : $answer);
            $this->line(sprintf(
                '<fg=gray>tools: %s · %.1f s%s</> %s',
                $used ? implode(', ', $used) : 'none',
                microtime(true) - $started,
                $turn['actions'] ? ' · proposed: '.implode('; ', array_map(fn ($a) => $a->summary, $turn['actions'])) : '',
                $ok ? '<fg=green>PASS</>' : '<fg=red>FAIL (expected '.implode(' and ', array_map(fn ($g) => implode(' or ', $g), $missing)).')</>',
            ));
        }

        $this->newLine();
        $this->info(sprintf('%d/%d passed. Tokens: %s in (%s from cache), %s out.', $passed, count(self::SCRIPT),
            number_format($tokens['input'] + $tokens['cache_read']), number_format($tokens['cache_read']), number_format($tokens['output'])));

        return $passed === count(self::SCRIPT) ? self::SUCCESS : self::FAILURE;
    }
}
