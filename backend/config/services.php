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

    'frontend_desktop' => [
        'url' => env('FRONTEND_DESKTOP_URL', 'http://127.0.0.1:8080'),
    ],

    'whatsapp' => [
        'gateway_path' => env(
            'WHATSAPP_API_PATH',
            base_path('../sistema-hml/whatsapp-api')
        ),
    ],

    'collector' => [
        'token' => env('COLLECTOR_API_TOKEN'),
        'pairing_ttl_minutes' => (int) env('COLLECTOR_PAIRING_TTL_MINUTES', 30),
        'suggestions_timeout' => (int) env('EQUIPMENT_MODEL_SUGGESTIONS_TIMEOUT', 5),
        'local_root' => env('COLLECTOR_LOCAL_ROOT', 'C:\\JovemTechBenchCollector'),
        'published_root' => env('COLLECTOR_PUBLISHED_ROOT'),
    ],

];
