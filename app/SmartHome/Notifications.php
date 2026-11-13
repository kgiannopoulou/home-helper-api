<?php

namespace App\SmartHome;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEvent;
use App\Push\ExpoPush;
use App\SmartHome\Events\HomeEventRecorded;
use App\Support\HomeTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which smart-home events reach the household's phones
 * (docs/smart-home.md#notifications).
 *
 * Each event type is `always`, `night` (only between
 * homehelper.smart_home.night.from and .to, home time: a door opening at 3 am
 * is news, at 6 pm it's someone coming home) or `never`. The owner changes
 * them on the Smart home page. The same device and type is pushed at most
 * once per homehelper.smart_home.notify_every_minutes, so a door that's
 * opened ten times in a minute is one buzz.
 */
class Notifications
{
    /** type => [default, label] */
    public const TYPES = [
        'door.opened' => ['night', 'A door opens'],
        'window.opened' => ['night', 'A window opens'],
        'door.left_open' => ['always', 'A door is left open'],
        'window.left_open' => ['always', 'A window is left open'],
        'motion.detected' => ['never', 'Motion'],
        'humidity.high' => ['always', 'A room gets very humid'],
        'temperature.low' => ['always', 'A room gets cold'],
        'device.unavailable' => ['always', 'A device stops answering'],
        'hub.offline' => ['always', 'Home Assistant stops answering'],
        'automation.failed' => ['always', 'An automation fails'],
        'approval.requested' => ['always', 'Something needs your approval'],
    ];

    public const LEVELS = ['always', 'night', 'never'];

    public function __construct(private ExpoPush $push) {}

    public function handle(HomeEventRecorded $recorded): void
    {
        $event = $recorded->event;
        $household = Household::query()->find($event->household_id);
        if (! $household) {
            return;
        }
        $connection = $household->smartHomeConnection;
        if (! $this->wanted($connection, $event->type, $event->occurred_at)) {
            return;
        }
        $key = 'smart-home-notify:'.($event->device_id ?? $event->household_id).':'.$event->type;
        // Every approval request matters; the rest is throttled
        if ($event->type !== 'approval.requested' && ! Cache::add($key, 1, now()->addMinutes((int) config('homehelper.smart_home.notify_every_minutes')))) {
            return;
        }

        try {
            // Only the owner can approve, so only the owner is asked
            $to = $event->type === 'approval.requested'
                ? $household->users()->wherePivot('role', HouseholdRole::Owner->value)->get()
                : $household->users;
            $result = $this->push->toUsers($to, $this->title($event), $event->message, [
                'type' => 'smart_home_event', 'event' => $event->type, 'event_id' => $event->id, 'url' => '/smart-home',
            ]);
            if ($result['sent'] > 0) {
                $event->forceFill(['notified_at' => CarbonImmutable::now()])->save();
            }
        } catch (Throwable $e) {
            // A push outage must not lose the event or break the webhook
            Log::warning('Smart home push failed', ['event_id' => $event->id, 'error' => $e->getMessage()]);
        }
    }

    public function wanted(?SmartHomeConnection $connection, string $type, CarbonImmutable $at): bool
    {
        $level = self::settings($connection)[$type] ?? 'never';

        return match ($level) {
            'always' => true,
            'night' => self::isNight($at),
            default => false,
        };
    }

    /** @return array<string, string> type => level, defaults filled in */
    public static function settings(?SmartHomeConnection $connection): array
    {
        $saved = $connection !== null ? (array) $connection->notify : [];
        $out = [];
        foreach (self::TYPES as $type => [$default]) {
            $out[$type] = in_array($saved[$type] ?? null, self::LEVELS, true) ? $saved[$type] : $default;
        }

        return $out;
    }

    public static function isNight(CarbonImmutable $at): bool
    {
        $local = $at->setTimezone(HomeTime::zone())->format('H:i');
        $from = (string) config('homehelper.smart_home.night.from');
        $to = (string) config('homehelper.smart_home.night.to');

        return $from > $to ? ($local >= $from || $local < $to) : ($local >= $from && $local < $to);
    }

    private function title(SmartHomeEvent $event): string
    {
        return match (true) {
            str_starts_with($event->type, 'door.'), str_starts_with($event->type, 'window.') => '🚪 Home',
            str_starts_with($event->type, 'humidity.'), str_starts_with($event->type, 'temperature.') => '🌡️ Home',
            $event->type === 'approval.requested' => '🔐 Approval needed',
            default => '🏠 Home',
        };
    }
}
