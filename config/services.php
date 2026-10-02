<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'tomtom' => [
        'key' => env('TOMMTOM_API_KEY'),
        'traffic_url' => env('TOMMTOM_TRAFFIC_URL', 'https://api.tomtom.com/traffic/services/4'),
        'timeout' => (int) env('TOMMTOM_TIMEOUT', 15),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'openverse' => [
        // Token manual (opsional). Kalau diisi, dipakai langsung dan tidak pernah refresh.
        // Kalau kosong, token diambil otomatis dari client_credentials lalu di-cache.
        'token' => env('OPENVERSE_ACCESS_TOKEN'),
        'client_id' => env('OPENVERSE_CLIENT_ID'),
        'client_secret' => env('OPENVERSE_CLIENT_SECRET'),
        'user_agent' => env('OPENVERSE_USER_AGENT'),
        'auto_fetch_after_sync' => env('OPENVERSE_AUTO_FETCH_AFTER_SYNC', true),
        'auto_fetch_limit' => (int) env('OPENVERSE_AUTO_FETCH_LIMIT', 50),
        'auto_fetch_sleep_ms' => (int) env('OPENVERSE_AUTO_FETCH_SLEEP_MS', 1000),
        'queue_fetch' => env('OPENVERSE_QUEUE_FETCH', true),
    ],

    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        'model' => env('GROQ_MODEL', 'qwen/qwen3.8-27b'),
        'fast_model' => env('GROQ_FAST_MODEL', 'qwen/qwen3.8-27b'),
        'timeout' => (int) env('GROQ_TIMEOUT', 45),
        'max_tokens' => (int) env('GROQ_MAX_TOKENS', 900),
    ],

];
