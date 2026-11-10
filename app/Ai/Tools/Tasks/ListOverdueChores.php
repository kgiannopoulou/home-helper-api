<?php

namespace App\Ai\Tools\Tasks;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Queries\OverdueChoresQuery;
use App\Queries\SlippingChoresQuery;

class ListOverdueChores extends Tool
{
    public function name(): string
    {
        return 'list_overdue_chores';
    }

    public function label(): string
    {
        return 'Checked the chores';
    }

    public function description(): string
    {
        return 'Chores past their due day, most overdue first (id for mark_chore_done, room, how often, last done, days overdue), plus chores that keep slipping: done much less often than planned.';
    }

    public function run(array $input, ToolContext $context): array
    {
        return [
            'overdue' => (new OverdueChoresQuery($context->household, $context->today))->get(),
            'slipping' => (new SlippingChoresQuery($context->household, $context->today))->get(),
        ];
    }
}
