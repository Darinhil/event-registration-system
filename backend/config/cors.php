<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 0,
    // The API uses bearer tokens, not cookies.
    'supports_credentials' => false,
];
