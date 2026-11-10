<?php

namespace App\Ops;

use App\Notifications\OpsAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Something an operator should know about: a job that failed for good, a
 * queue that keeps growing, a backup that didn't happen, an error that keeps
 * coming back.
 *
 * Every alert is written to the `ops` log channel (JSON, storage/logs/ops-*.log)
 * and kept in a short list the health page shows. It is emailed to
 * OPS_ALERT_EMAIL at most once per cooldown per key, so a job that fails for
 * every household sends one email, not hundreds.
 */
class Alerts
{
    private const RECENT_KEY = 'ops:recent-alerts';

    private const RECENT_MAX = 20;

    /**
     * @param  string  $key  what this alert is about, e.g. "job-failed:BudgetAlert"; repeats inside the cooldown aren't emailed
     * @param  array<string, mixed>  $context
     * @return bool whether it was emailed
     */
    public static function raise(string $key, string $title, array $context = []): bool
    {
        $context = ['alert' => $key] + $context;
        Log::channel('ops')->error($title, $context);

        self::remember($key, $title, $context);

        $cooldown = CarbonImmutable::now()->addMinutes(max(1, (int) config('homehelper.ops.alert_cooldown_minutes')));
        try {
            $first = Cache::add("ops:alerted:{$key}", true, $cooldown);
        } catch (Throwable) {
            $first = true; // the cache is down (perhaps that's the alert): better twice than never
        }
        if (! $first) {
            return false;
        }

        $email = config('homehelper.ops.alert_email');
        if (! $email) {
            return false;
        }

        // Sent now, not queued: the queue may be the thing that's broken
        try {
            Notification::route('mail', $email)->notifyNow(new OpsAlert($title, $context));
        } catch (Throwable $e) {
            Log::channel('ops')->critical('Could not email an ops alert', ['alert' => $key, 'error' => $e->getMessage()]);

            return false;
        }

        return true;
    }

    /**
     * The newest alerts first, for the health page.
     *
     * @return list<array{key: string, title: string, at: string, context: array<string, mixed>}>
     */
    public static function recent(): array
    {
        return Cache::get(self::RECENT_KEY, []);
    }

    /** @param array<string, mixed> $context */
    private static function remember(string $key, string $title, array $context): void
    {
        try {
            $recent = self::recent();
            array_unshift($recent, [
                'key' => $key,
                'title' => $title,
                'at' => CarbonImmutable::now()->toIso8601String(),
                'context' => $context,
            ]);
            Cache::forever(self::RECENT_KEY, array_slice($recent, 0, self::RECENT_MAX));
        } catch (Throwable) {
            // The cache being down must not stop the alert itself
        }
    }
}
