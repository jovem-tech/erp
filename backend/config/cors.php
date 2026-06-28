<?php

$isLocalEnvironment = in_array(env('APP_ENV'), ['local', 'testing'], true);

$defaultLocalOrigins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:3002',
    'http://localhost:3003',
    'http://localhost:3004',
    'http://localhost:3005',
    'http://localhost:8000',
    'http://localhost:8080',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'http://127.0.0.1:3002',
    'http://127.0.0.1:3003',
    'http://127.0.0.1:3004',
    'http://127.0.0.1:3005',
    'http://127.0.0.1:8000',
    'http://127.0.0.1:8080',
];

$configuredAllowedOrigins = env('CORS_ALLOWED_ORIGINS');
$allowedOrigins = $configuredAllowedOrigins !== null
    ? array_values(array_filter(array_map('trim', explode(',', $configuredAllowedOrigins))))
    : ($isLocalEnvironment ? $defaultLocalOrigins : []);

$configuredAllowedOriginPatterns = env('CORS_ALLOWED_ORIGIN_PATTERNS');
$allowedOriginPatterns = $configuredAllowedOriginPatterns !== null
    ? array_values(array_filter(array_map('trim', explode(',', $configuredAllowedOriginPatterns))))
    : ($isLocalEnvironment ? ['#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#'] : []);

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure CORS settings for your application. This will
    | allow cross-origin requests from specified domains.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    |
    | HandleCors so atua nos paths listados aqui (default do Laravel: so
    | "api/*" e "sanctum/csrf-cookie"). "broadcasting/auth" precisa estar aqui
    | explicitamente porque a Central de Atendimento (frontends/chat, outra
    | origem/porta) autentica o canal WebSocket via fetch() do navegador
    | direto nessa rota, fora do prefixo /api — ver
    | specs/010-inbox-whatsapp-tempo-real/plan.md.
    |
    */
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | Specify the origins that are allowed to access your API.
    | Use '*' to allow all origins (not recommended for production).
    |
    */
    'allowed_origins' => $allowedOrigins,

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins Patterns
    |--------------------------------------------------------------------------
    |
    | You may specify a pattern for the allowed origins to handle wildcards.
    |
    */
    'allowed_origins_patterns' => $allowedOriginPatterns,

    /*
    |--------------------------------------------------------------------------
    | Allowed HTTP Methods
    |--------------------------------------------------------------------------
    |
    | Specify which HTTP methods are allowed for CORS requests.
    |
    */
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    /*
    |--------------------------------------------------------------------------
    | Allowed HTTP Headers
    |--------------------------------------------------------------------------
    |
    | Specify which headers clients are allowed to send with CORS requests.
    |
    */
    'allowed_headers' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Exposed HTTP Headers
    |--------------------------------------------------------------------------
    |
    | Specify which headers should be exposed to the client browser.
    |
    */
    'exposed_headers' => [
        'x-request-id',
        'x-response-time',
    ],

    /*
    |--------------------------------------------------------------------------
    | Max Age
    |--------------------------------------------------------------------------
    |
    | Specify the cache duration for preflight requests (in seconds).
    | 0 means the result cannot be cached.
    |
    */
    'max_age' => 86400,

    /*
    |--------------------------------------------------------------------------
    | Supports Credentials
    |--------------------------------------------------------------------------
    |
    | Whether to allow credentials (cookies, authorization headers, TLS
    | client certificates) to be included in cross-origin requests.
    | IMPORTANT: Required for HttpOnly cookies to work across origins.
    |
    */
    'supports_credentials' => true,
];
