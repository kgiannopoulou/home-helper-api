<?php

namespace App\Jobs;

use App\Memory\MemoryIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-embeds one remembered thing (a note, a fact, a summary, a conversation)
 * after it changed. Queued, so a slow embedding service never slows down
 * saving a note; after the commit, so it reads what was saved.
 */
class IndexMemory implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** Deleted before it ran: MemoryObserver already removed its passages */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Model $source)
    {
        $this->afterCommit();
    }

    public function handle(MemoryIndexer $indexer): void
    {
        $indexer->index($this->source);
    }
}
