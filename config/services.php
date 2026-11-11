<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Push notifications to the phone app (Phase 5). The access token is optional:
    // set it only if "enhanced push security" is turned on for the Expo project.
    'expo' => [
        'push_url' => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

    // Weather for plant care: free, no key (https://open-meteo.com)
    'open_meteo' => [
        'url' => env('OPEN_METEO_URL', 'https://api.open-meteo.com/v1/forecast'),
    ],

    // The assistant's model (config/homehelper.php → ai)
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 120),
    ],

    // Embeddings for semantic search when MEMORY_EMBEDDER=voyage (docs/memory.md#embeddings)
    'voyage' => [
        'api_key' => env('VOYAGE_API_KEY'),
        'timeout' => (int) env('VOYAGE_TIMEOUT', 30),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
