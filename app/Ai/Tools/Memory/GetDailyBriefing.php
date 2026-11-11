<?php

namespace App\Ai\Tools\Memory;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Briefing\BriefingService;

class GetDailyBriefing extends Tool
{
    public function __construct(private BriefingService $briefings) {}

    public function name(): string
    {
        return 'get_daily_briefing';
    }

    public function label(): string
    {
        return 'Put together the daily briefing';
    }

    public function description(): string
    {
        return 'This person\'s briefing for today: weather, calendar today and tomorrow, to-dos and chores (marked when they are theirs), shopping list and what is running out, food to use soon, money (yesterday, this month, the budget pace), bills this week, plants, home upkeep, and personalised recommendations with their reasons. Use it for "brief me", "good morning", "what should I focus on" or "any suggestions".';
    }

    public function run(array $input, ToolContext $context): array
    {
        $b = $this->briefings->build($context->household, $context->user, $context->today);

        return [
            'headline' => $b['headline'],
            'briefing' => BriefingService::text($b),
            'recommendations' => array_map(fn (array $r) => ['title' => $r['title'], 'reason' => $r['reason']], $b['recommendations']),
        ];
    }
}
