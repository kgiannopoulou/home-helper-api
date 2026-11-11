<?php

namespace App\Jobs;

use App\Models\AiConversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hourly: conversations that went quiet (no message for an hour, the last
 * week) and have something new since their summary are summarised into
 * long-term memory, one job each.
 */
class RememberConversations implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $quietSince = now()->subMinutes((int) config('homehelper.memory.remember_after_minutes'));

        AiConversation::query()
            ->where('updated_at', '<', $quietSince)
            ->where('updated_at', '>', now()->subDays(7))
            ->whereExists(fn (Builder $q) => $q->from('ai_messages')
                ->whereColumn('ai_messages.conversation_id', 'ai_conversations.id')
                ->where('ai_messages.role', 'user')
                ->whereRaw('ai_messages.id > coalesce(ai_conversations.summary_through_id, 0)'))
            ->select('id')->lazyById(200)
            ->each(fn (AiConversation $c) => dispatch(new SummarizeConversation($c, whole: true)));
    }
}
