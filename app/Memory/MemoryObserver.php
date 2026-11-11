<?php

namespace App\Memory;

use App\Enums\MemorySource;
use App\Enums\NoteKind;
use App\Jobs\IndexMemory;
use App\Models\AiConversation;
use App\Models\HouseholdFact;
use App\Models\HouseholdSummary;
use App\Models\Note;
use Illuminate\Database\Eloquent\Model;

/**
 * Notes, facts and summaries are re-indexed when they are saved and forgotten
 * when they are deleted, so search never finds something that is gone.
 * Registered in AppServiceProvider. Conversations are indexed by
 * ConversationMemory when they are summarised, and forgotten here.
 */
class MemoryObserver
{
    public function saved(Model $model): void
    {
        if ($model instanceof AiConversation) {
            return;
        }
        // A note that turned into a document (or back) leaves its old passages behind
        if ($model instanceof Note && $model->wasChanged('kind')) {
            $this->forget($model, $model->kind === NoteKind::Document ? MemorySource::Note : MemorySource::Document);
        }
        dispatch(new IndexMemory($model));
    }

    public function deleted(Model $model): void
    {
        $this->forget($model, match (true) {
            $model instanceof Note => $model->kind === NoteKind::Document ? MemorySource::Document : MemorySource::Note,
            $model instanceof HouseholdFact => MemorySource::Fact,
            $model instanceof HouseholdSummary => MemorySource::Summary,
            $model instanceof AiConversation => MemorySource::Conversation,
            default => null,
        });
    }

    private function forget(Model $model, ?MemorySource $type): void
    {
        if ($type) {
            app(MemoryIndexer::class)->forget($type, (string) $model->getKey());
        }
    }
}
