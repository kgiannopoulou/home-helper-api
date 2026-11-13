<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A receipt read from a photo or pasted text (App\Kitchen\Receipts).
 *
 * @property string $id
 * @property string $household_id
 * @property int|null $user_id
 * @property string $status draft|imported|discarded
 * @property string $source photo|text
 * @property string|null $store
 * @property CarbonImmutable|null $purchased_on
 * @property float|null $total
 * @property bool $adds_up
 * @property string $ocr_text card numbers masked
 * @property list<array<string, mixed>> $lines App\Kitchen\Receipts\ParsedReceipt::$lines
 * @property string|null $image_path on the local (private) disk, only while a draft
 * @property string|null $shopping_trip_id
 * @property CarbonImmutable|null $imported_at
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 */
class Receipt extends Model
{
    use BelongsToHousehold, HasUlids, Prunable;

    protected $guarded = ['id', 'household_id'];

    protected function casts(): array
    {
        return [
            'purchased_on' => 'immutable_date',
            'total' => 'float',
            'adds_up' => 'boolean',
            'lines' => 'array',
            'imported_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // The photo goes with the row
        static::deleting(fn (Receipt $r) => $r->forgetImage());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Drafts nobody finished within a week, and their photos
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('status', '!=', 'imported')->where('created_at', '<', now()->subDays(7));
    }

    public function forgetImage(): void
    {
        if ($this->image_path) {
            Storage::disk('local')->delete($this->image_path);
            $this->image_path = null;
        }
    }

    /** Long digit runs (a full card number, an IBAN's digits) become [hidden]; "****4821" is already masked */
    public static function scrub(string $text): string
    {
        return (string) preg_replace('/(?<!\d)\d(?:[ -]?\d){11,}(?!\d)/u', '[hidden]', $text);
    }
}
