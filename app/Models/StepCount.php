<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Database\Factories\StepCountFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One person's steps on a day.
 */
#[Fillable(['user_id', 'date', 'steps'])]
class StepCount extends Model
{
    /** @use HasFactory<StepCountFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    private const CROCKFORD = '0123456789abcdefghjkmnpqrstvwxyz';

    /**
     * A new row without an id (the API, the seeder) gets the id the phone would make.
     * A row from sync keeps the id the phone sent.
     */
    public function newUniqueId(): string
    {
        return $this->user_id && $this->date
            ? self::idFor($this->user_id, $this->date)
            : strtolower((string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'steps' => 'integer',
        ];
    }

    /**
     * A ULID made from the day and the person, the same as stepsId() on the phone:
     * the time part is midnight UTC of the day, the "random" part is the user id.
     * "2026-10-04" for user 1 → "01m423bp00" + "0000000000000001".
     */
    public static function idFor(int $userId, DateTimeInterface|string $date): string
    {
        $day = CarbonImmutable::parse($date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date, 'UTC')->startOfDay();

        return self::base32((int) $day->getTimestampMs(), 10).self::base32($userId, 16);
    }

    private static function base32(int $n, int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out = self::CROCKFORD[$n % 32].$out;
            $n = intdiv($n, 32);
        }

        return $out;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
