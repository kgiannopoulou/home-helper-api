<?php

namespace App\Models;

use App\Enums\JobStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One run of a scheduled household job (app/Jobs).
 *
 * @property int $id
 * @property string $household_id
 * @property string $job
 * @property JobStatus $status
 * @property Carbon $for_date
 * @property int $attempt
 * @property array<string, mixed>|null $summary
 * @property string|null $error
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 */
class JobRun extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'for_date' => 'date',
            'attempt' => 'integer',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    public static function start(Household $household, string $job, CarbonImmutable $forDate, int $attempt): self
    {
        return self::create([
            'household_id' => $household->id,
            'job' => $job,
            'status' => JobStatus::Running,
            'for_date' => $forDate->toDateString(),
            'attempt' => $attempt,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function succeeded(array $summary): void
    {
        $this->finish(JobStatus::Succeeded, ['summary' => $summary]);
    }

    public function failed(Throwable $e): void
    {
        $this->finish(JobStatus::Failed, ['error' => $e::class.': '.$e->getMessage()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function finish(JobStatus $status, array $attributes): void
    {
        $finished = now();
        $this->update([
            ...$attributes,
            'status' => $status,
            'finished_at' => $finished,
            'duration_ms' => (int) round($this->started_at->diffInMilliseconds($finished, true)),
        ]);
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
