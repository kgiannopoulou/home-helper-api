<?php

namespace App\Console\Commands;

use App\Memory\Embedder;
use App\Memory\HouseholdDigest;
use App\Memory\MemoryIndexer;
use App\Models\AiConversation;
use App\Models\Household;
use App\Models\HouseholdFact;
use App\Models\HouseholdSummary;
use App\Models\MemoryChunk;
use App\Models\Note;
use App\Support\HomeTime;
use Illuminate\Console\Command;

/**
 * php artisan memory:reindex — embeds every note, fact, summary and
 * conversation summary again (after changing MEMORY_EMBEDDER), and with
 * --digests writes the weekly and monthly summaries of the past, so a
 * household with history has them from the start.
 */
class MemoryReindex extends Command
{
    protected $signature = 'memory:reindex
        {--household= : only this household id}
        {--digests=0 : also write summaries for this many past months (weeks and months)}';

    protected $description = 'Re-embed long-term memory, and optionally write past household summaries';

    public function handle(MemoryIndexer $indexer, HouseholdDigest $digest, Embedder $embedder): int
    {
        $households = Household::query()->when($this->option('household'), fn ($q, $id) => $q->whereKey($id))->get();
        $months = (int) $this->option('digests');
        $today = HomeTime::today();

        foreach ($households as $household) {
            if ($months > 0) {
                $written = 0;
                for ($m = $months; $m >= 1; $m--) {
                    $digest->write($household, 'month', $today->subMonthsNoOverflow($m));
                    $written++;
                }
                // Every full week since the first month, up to last week
                for ($week = $today->subMonthsNoOverflow($months)->startOfMonth(); $week->addDays(6)->lt($today); $week = $week->addWeek()) {
                    $digest->write($household, 'week', $week);
                    $written++;
                }
                $this->line("{$household->name}: {$written} summaries written");
            }

            $passages = 0;
            $sources = [
                ...Note::query()->whereBelongsTo($household)->get(),
                ...HouseholdFact::query()->whereBelongsTo($household)->with('about')->get(),
                ...HouseholdSummary::query()->whereBelongsTo($household)->get(),
                ...AiConversation::query()->where('household_id', $household->id)->whereNotNull('summary')->get(),
            ];
            foreach ($sources as $source) {
                $passages += $indexer->index($source);
            }
            $this->info("{$household->name}: ".count($sources)." sources, {$passages} passages ({$embedder->name()})");
        }

        $stale = MemoryChunk::query()->where('embedder', '!=', $embedder->name())->count();
        if ($stale) {
            $this->warn("{$stale} passages were embedded by another embedder and are not searched by meaning. Run without --household to re-embed them.");
        }

        return self::SUCCESS;
    }
}
