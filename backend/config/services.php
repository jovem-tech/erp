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
        // Coletor Linux (script, nao binario): mesma convencao de pasta fixa
        // do Windows, so que embaixo de /home em vez de C:\.
        'local_root_linux' => env('COLLECTOR_LOCAL_ROOT_LINUX', '/home/JovemTechBenchCollector'),
        'published_root_linux' => env('COLLECTOR_PUBLISHED_ROOT_LINUX'),
    ],

    'rbac' => [
        // Botao de emergencia do RBAC: quando true, usuario ativo com
        // perfil=admin e SEM grupo (grupo_id nulo/0) recebe todas as
        // permissoes (comportamento legado, anterior a v4.0.0.0). Default
        // desligado — so ligar (RBAC_LEGACY_ADMIN_FALLBACK=true) se um deploy
        // revelar admins legados sem grupo que perderam acesso.
        'legacy_admin_fallback' => (bool) env('RBAC_LEGACY_ADMIN_FALLBACK', false),
    ],

];
