<?php

namespace App\Jobs;

use App\Kitchen\Expiry;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Push\ExpoPush;
use Carbon\CarbonImmutable;

/**
 * Every morning (docs/kitchen.md#expiry): what's in the kitchen and goes off
 * today or tomorrow, or already has, in one push to the household.
 *
 * Each item is mentioned once per date: a push for the milk going off on
 * Friday, not every morning until Friday. A new date (a new pack, opened,
 * "still good") makes it count again. When nothing new is due, no push.
 * Nothing is thrown away by itself: an expired item stays until someone
 * says "thrown away" or "still good".
 */
class CheckExpiringFood extends HouseholdJob
{
    /** Up to tomorrow */
    public const AHEAD_DAYS = 1;

    protected function run(Household $household, CarbonImmutable $today): array
    {
        $due = app(Expiry::class)->expiring($household, $today, self::AHEAD_DAYS)
            ->filter(fn (InventoryItem $i) => $i->expiry_notified_on?->toDateString() !== $i->expires_on?->toDateString());
        if ($due->isEmpty()) {
            return ['due' => 0];
        }

        $words = $due->values()->map(fn (InventoryItem $i) => $i->name.' ('.self::when(Expiry::status($i, $today)['days'] ?? 0).')')->values()->all();
        /** @var list<string> $words */
        $expired = $due->filter(fn (InventoryItem $i) => (Expiry::status($i, $today)['days'] ?? 0) < 0)->count();
        $push = app(ExpoPush::class)->toUsers(
            $household->users,
            $expired === $due->count() ? '🗑️ Gone off in the kitchen' : '🥛 Use soon',
            self::text($words),
            ['type' => 'kitchen_expiry', 'url' => '/kitchen'],
        );

        // Mentioned: not again for this date. Only once a phone got it, so turning push on later still tells you
        if ($push['sent'] > 0) {
            $due->each(fn (InventoryItem $i) => $i->forceFill(['expiry_notified_on' => $i->expires_on])->saveQuietly());
        }

        return ['due' => $due->count(), 'items' => $due->pluck('name')->all(), 'push' => $push];
    }

    public static function when(int $days): string
    {
        return match (true) {
            $days < -1 => -$days.' days ago',
            $days === -1 => 'yesterday',
            $days === 0 => 'today',
            $days === 1 => 'tomorrow',
            default => "in {$days} days",
        };
    }

    /** @param list<string> $words */
    private static function text(array $words): string
    {
        $count = count($words);
        $shown = $count > 4 ? [...array_slice($words, 0, 3), ($count - 3).' more'] : $words;
        $last = array_pop($shown);

        return ($shown ? implode(', ', $shown)." and {$last}" : $last).'.';
    }
}
