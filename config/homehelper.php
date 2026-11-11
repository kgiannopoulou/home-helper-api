<?php

return [

    /*
    | The time zone of the household day. Scheduled jobs run on its clock
    | ("18:00" is 18:00 at home) and use its date as "today", so a bill due
    | on the 5th is added on the 5th at home, whatever the server's zone is.
    */
    'timezone' => env('HOME_TIMEZONE', 'UTC'),

    /*
    | Plant care (CheckPlantCare, hourly). Weather rules apply to plants out in
    | the weather (outdoor, balcony) and read one forecast per household for the
    | next 24 hours. Reminders that can wait (watering, fertilizer, repotting) are
    | only raised between the reminder hours at home; weather warnings any time.
    */
    'plants' => [
        'reminder_hours' => [8, 21],
        'overdue_after_days' => 2,
        // This much rain and an outdoor plant's watering is postponed
        'rain_skips_watering_mm' => 5,
        // This much rain and rain-sensitive plants should go under cover
        'heavy_rain_mm' => 20,
        'strong_wind_kmh' => 50,
        // Frost can form on leaves below this air temperature
        'frost_c' => 2,
        // Hot or cool days change how much an outdoor plant needs
        'hot_c' => 30,
        'cool_c' => 15,
        'forecast_cache_minutes' => 30,
        // A weather warning the household dealt with isn't raised again this soon
        'weather_cooldown_hours' => 12,
    ],

    /*
    | Running it (docs/operations.md). Admins may open /system/health; alerts
    | (failed jobs, a full queue, a failed backup, repeated server errors) are
    | logged on the `ops` channel and emailed to alert_email when it is set.
    */
    'ops' => [
        'admins' => array_values(array_filter(array_map('trim', explode(',', (string) env('OPS_ADMIN_EMAILS', ''))))),
        'alert_email' => env('OPS_ALERT_EMAIL'),
        // The same alert is not sent again for this long
        'alert_cooldown_minutes' => (int) env('OPS_ALERT_COOLDOWN_MINUTES', 15),
        // One query slower than this is logged as a warning
        'slow_query_ms' => (int) env('LOG_SLOW_QUERY_MS', 500),
    ],

    /*
    | Queues on Redis. Workers listen to them in this order:
    |   php artisan queue:work redis --queue=default,households
    | queue:monitor alerts when one holds more than max_waiting jobs.
    */
    'queues' => [
        'households' => env('QUEUE_HOUSEHOLDS', 'households'),
        'monitored' => ['default', env('QUEUE_HOUSEHOLDS', 'households')],
        'max_waiting' => (int) env('QUEUE_MAX_WAITING', 100),
    ],

    /*
    | Database backups (php artisan db:backup, db:restore-test). mysqldump
    | output is gzipped onto `disk` under `path`; the newest `keep` are kept.
    | Use an off-server disk (s3) in production: a backup on the same machine
    | is lost with it.
    */
    'backups' => [
        'disk' => env('BACKUP_DISK', 'local'),
        'path' => env('BACKUP_PATH', 'backups'),
        'keep' => (int) env('BACKUP_KEEP', 14),
        'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
        'mysql' => env('BACKUP_MYSQL', 'mysql'),
        // The health page warns when the newest backup is older than this
        'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
        // The restore test goes into this database, created and dropped each time
        'restore_database' => env('BACKUP_RESTORE_DATABASE', 'home_helper_restore_test'),
    ],

    /*
    | The assistant (docs/assistant.md). `provider`:
    |   claude   Claude through the Anthropic API (needs ANTHROPIC_API_KEY)
    |   offline  a keyword router with no model: answers the common questions
    |            from the same tools, for local use without a key
    | Every write the assistant proposes waits for the person to confirm it.
    */
    'ai' => [
        'provider' => env('AI_PROVIDER', 'claude'),
        'model' => env('AI_MODEL', 'claude-opus-5-5'),
        // Thinking depth and token spend; household questions rarely need more
        'effort' => env('AI_EFFORT', 'medium'),
        'max_tokens' => (int) env('AI_MAX_TOKENS', 16000),
        // Model → tools → model rounds in one reply, before it stops
        'max_tool_rounds' => 8,
        // A conversation longer than this has to start over. Older messages are
        // summarised (homehelper.memory), so this is a safety stop, not the context size
        'max_messages' => 1000,
        'confirmation_minutes' => 30,
        // Messages a person may send per day, across all their conversations
        'daily_messages' => (int) env('AI_DAILY_MESSAGES', 200),
        // false: the assistant only reads, even with confirmation
        'allow_writes' => (bool) env('AI_ALLOW_WRITES', true),
    ],

    /*
    | Memory (docs/memory.md). `embedder`:
    |   local   feature hashing in PHP: no key, no network, matches words and
    |           their forms (descale ≈ descaling), not meaning
    |   voyage  Voyage AI embeddings (needs VOYAGE_API_KEY): real semantic search
    | After changing it, re-embed everything: php artisan memory:reindex
    */
    'memory' => [
        'embedder' => env('MEMORY_EMBEDDER', 'local'),
        'voyage_model' => env('VOYAGE_MODEL', 'voyage-4'),
        'dimensions' => (int) env('MEMORY_DIMENSIONS', 512),
        // Passages are cut at paragraph breaks to about this many characters
        'chunk_chars' => 900,
        // A conversation with more unsummarised messages than this is compacted:
        // the older part becomes a summary, the newest keep_recent stay verbatim
        'summarize_after' => (int) env('AI_SUMMARIZE_AFTER', 40),
        'keep_recent' => 16,
        // An idle conversation is summarised into long-term memory after this long
        'remember_after_minutes' => 60,
        // Household facts written into every assistant context (newest first)
        'context_facts' => 60,
        // Upload limit for documents, in kilobytes
        'max_upload_kb' => 5120,
    ],

    /*
    | The morning briefing (docs/briefings.md), at home time, for every member.
    */
    'briefing' => [
        'time' => env('BRIEFING_TIME', '07:00'),
        'max_recommendations' => 5,
        // "Not now" hides a recommendation for this many days
        'dismiss_days' => 7,
    ],

    /*
    | System health (/api/health, /system/health). The scheduler writes a
    | heartbeat every minute; older than this and it counts as stopped.
    */
    'health' => [
        'scheduler_stale_minutes' => 5,
        'disk_min_free_mb' => (int) env('HEALTH_DISK_MIN_FREE_MB', 1024),
    ],

];
