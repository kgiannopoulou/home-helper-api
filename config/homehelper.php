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
    | Voice (docs/voice.md). `driver`:
    |   service  the voice service (services/voice): Whisper, Piper, openWakeWord, all local
    |   browser  no service: the browser's own speech recognition and voices
    |            (Chrome sends that audio to Google); no wake word
    |   fake     tests: audio "TEXT:…" says the rest, speech is a short tone
    */
    'voice' => [
        'driver' => env('VOICE_DRIVER', 'browser'),
        'url' => env('VOICE_SERVICE_URL', 'http://voice:8090'),
        'token' => env('VOICE_SERVICE_TOKEN'),
        // Where browsers reach the wake-word socket (the service's public address)
        'wake_url' => env('VOICE_WAKE_URL', 'ws://localhost:8090/v1/wake'),
        'wake_phrase' => env('VOICE_WAKE_PHRASE', 'Hey Jarvis'),
        'timeout' => (int) env('VOICE_TIMEOUT', 60),
        'max_upload_kb' => 10240,
        // A spoken answer can be fetched (and replayed) for this long, then it is gone
        'audio_minutes' => 10,
        'voices' => ['en_GB-alan-medium', 'en_US-lessac-medium', 'en_GB-jenny_dioco-medium', 'en_US-ryan-high', 'el_GR-rapunzelina-low'],
        // How the assistant answers out loud (App\Voice\ResponseStyle)
        'styles' => ['brief', 'conversational', 'detailed', 'butler'],
        // Voice conversations may be deleted after this many days (a member's choice; null keeps them)
        'keep_days_options' => [1, 7, 30],
    ],

    /*
    | System health (/api/health, /system/health). The scheduler writes a
    | heartbeat every minute; older than this and it counts as stopped.
    */
    'health' => [
        'scheduler_stale_minutes' => 5,
        'disk_min_free_mb' => (int) env('HEALTH_DISK_MIN_FREE_MB', 1024),
    ],

    /*
    | The local agent on the household's computers (agent/docs/design.md,
    | agent/docs/protocol.md, docs/agents.md).
    */
    'agents' => [
        // A pairing code works once, for this long
        'code_minutes' => 10,
        // How often agents check in; offline after this many missed heartbeats
        'heartbeat_seconds' => (int) env('AGENT_HEARTBEAT_SECONDS', 30),
        'offline_after_heartbeats' => 4,
        // Signed requests: how far the agent's clock may be off, how long a nonce is remembered
        'clock_skew_seconds' => 300,
        'nonce_minutes' => 10,
        // Metrics history for the dashboard charts
        'metrics_keep_days' => 7,
        // A queued command the agent hasn't picked up by then expires
        'command_minutes' => 10,
        'output_kb' => 16,
        // Ed25519 key that signs agent releases (php artisan agent:release-key)
        'release_public_key' => env('AGENT_RELEASE_PUBLIC_KEY'),
        'release_secret_key' => env('AGENT_RELEASE_SECRET_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Smart home (Phase 6)
    |--------------------------------------------------------------------------
    |
    | Home Assistant as the hub for lights, plugs and sensors (docs/smart-home.md).
    */
    'smart_home' => [
        // Home Assistant is on the local network: a button should fail fast
        'timeout_seconds' => (int) env('SMART_HOME_TIMEOUT_SECONDS', 5),
        // Temperature and humidity history (App\SmartHome\Readings)
        'readings_keep_days' => 30,
        // A sensor that hasn't changed still gets a point this often, so the charts have no gaps
        'reading_every_minutes' => 15,
        // The house's event log (App\SmartHome\HomeEvents)
        'events_keep_days' => 60,
        // A warning when a door or window has been open this long (App\SmartHome\Openings)
        'left_open_minutes' => ['door' => 10, 'window' => 30],
        // How a room feels (App\SmartHome\Comfort)
        'comfort' => [
            'temperature_low' => 18.0,
            'temperature_high' => 26.0,
            'humidity_low' => 30.0,
            'humidity_high' => 65.0,
            'humidity_very_high' => 70.0,
        ],
    ],

];
