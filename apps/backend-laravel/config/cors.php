<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| The frontend (e.g. Next.js on http://localhost:4200) calls the API from
| a different origin. Browsers send an OPTIONS preflight first.
| The server MUST respond to OPTIONS with 200 + CORS headers (no redirect).
|
| If you see "Redirect is not allowed for a preflight request", the backend
| is redirecting OPTIONS (e.g. to login). Fix: ensure HandleCors runs first
| and OPTIONS is never redirected (exclude OPTIONS from auth redirect, or
| run CORS middleware before any redirect middleware).
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter(array_merge(
        [
            'http://localhost:3000',
            'http://localhost:4200',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:4200',
        ],
        env('CORS_ALLOWED_ORIGINS') ? array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS'))) : []
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,

];
