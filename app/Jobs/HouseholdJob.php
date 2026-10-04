<?php

namespace App\Jobs;

use App\Models\Household;
use App\Models\JobRun;
use App\Support\HomeTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * A scheduled job that works on one household at a time.
 *
 * The scheduler dispatches it without a household (`Schedule::job(new BudgetAlert)`).
 * That run only fans out: it queues one copy per household, all for the same day
 * at home, so households are independent (one failing doesn't stop the rest) and
 * workers can run them side by side. Each household's run is logged in job_runs.
 */
abstract class HouseholdJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 120;

    /** The household was deleted while the job waited: drop it quietly. */
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  string|null  $date  "today" at home, YYYY-MM-DD; set when fanning out so a run after midnight keeps its day
     */
    final public function __construct(
        public ?Household $household = null,
        public ?string $date = null,
    ) {}

    /**
     * Does the work for one household.
     *
     * @return array<string, mixed> what it did, saved in job_runs.summary
     */
    abstract protected function run(Household $household, CarbonImmutable $today): array;

    public function handle(): void
    {
        $today = $this->date
            ? CarbonImmutable::parse($this->date, self::timezone())
            : self::today();

        if (! $this->household) {
            $this->fanOut($today);

            return;
        }

        $run = JobRun::start($this->household, static::name(), $today, $this->attempts());
        try {
            $run->succeeded($this->run($this->household, $today));
        } catch (Throwable $e) {
            $run->failed($e);
            throw $e;
        }
    }

    private function fanOut(CarbonImmutable $today): void
    {
        Household::query()->select('id')->lazyById(200)->each(
            fn (Household $household) => dispatch(new static($household, $today->toDateString())),
        );
    }

    /** "PrepareShoppingList", as stored in job_runs.job */
    public static function name(): string
    {
        return class_basename(static::class);
    }

    public static function timezone(): string
    {
        return HomeTime::zone();
    }

    /** Today at home, at midnight. */
    public static function today(): CarbonImmutable
    {
        return HomeTime::today();
    }
}
