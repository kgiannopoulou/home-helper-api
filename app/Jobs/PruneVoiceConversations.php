<?php

namespace App\Jobs;

use App\Models\AiConversation;
use App\Models\VoiceSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily: voice conversations older than their person's chosen retention
 * (voice_settings.keep_days) are deleted, with their messages, pending
 * actions and summaries in memory (docs/voice.md#privacy).
 */
class PruneVoiceConversations implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $deleted = 0;
        VoiceSettings::query()->whereNotNull('keep_days')->each(function (VoiceSettings $s) use (&$deleted) {
            AiConversation::query()->where('user_id', $s->user_id)->where('channel', 'voice')
                ->where('updated_at', '<', now()->subDays((int) $s->keep_days))
                // One by one, so the observer also forgets their summaries
                ->each(function (AiConversation $c) use (&$deleted) {
                    $c->delete();
                    $deleted++;
                });
        });

        if ($deleted > 0) {
            logger()->info('Pruned voice conversations', ['deleted' => $deleted]);
        }
    }
}
