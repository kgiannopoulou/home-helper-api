<?php

namespace App\Ai\Tools\Tasks;

use App\Ai\Tools\Concerns\HouseholdData;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\Event;

class ListUpcomingEvents extends Tool
{
    use HouseholdData;

    public function name(): string
    {
        return 'list_upcoming_events';
    }

    public function label(): string
    {
        return 'Looked at the calendar';
    }

    public function description(): string
    {
        return 'Calendar events from today for the next N days (default 7), in order, with start time at home and location.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 31, 'description' => 'Default 7']],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return ['days' => ['sometimes', 'integer', 'between:1,31']];
    }

    public function run(array $input, ToolContext $context): array
    {
        $from = $context->today;
        $to = $from->addDays($input['days'] ?? 7);

        $events = Event::query()->whereBelongsTo($context->household)
            ->where('starts_at', '>=', $from->setTimezone('UTC'))
            ->where('starts_at', '<', $to->setTimezone('UTC'))
            ->orderBy('starts_at')->limit(50)->get()
            ->map(fn (Event $e) => [
                'title' => $e->title,
                'starts' => $e->all_day ? $e->starts_at->setTimezone($from->timezone)->toDateString().' (all day)' : $this->homeTime($e->starts_at),
                'ends' => $e->all_day ? null : $this->homeTime($e->ends_at),
                'location' => $e->location,
            ])->all();

        return ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString(), 'events' => $events];
    }
}
