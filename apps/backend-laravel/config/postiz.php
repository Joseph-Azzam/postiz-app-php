<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SSL verify for outbound HTTPS (project-wide)
    |--------------------------------------------------------------------------
    |
    | Set to false only in .env.local for WAMP/XAMPP (cURL error 60). Do not set
    | in production .env; default is true.
    |
    */
    'ssl_verify' => filter_var(env('SSL_VERIFY', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Postiz Debug Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, we log every cron run + every post state transition.
    | This is mandatory per `.cursorrules` for migration visibility.
    |
    | IMPORTANT: This must remain stateless and safe on shared hosting.
    */
    'debug' => env('POSTIZ_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Test dashboard key (optional)
    |--------------------------------------------------------------------------
    | When set, /test requires ?key=<value> to run. Leave empty for no protection.
    */
    'test_key' => env('POSTIZ_TEST_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Cron batch size (safety limit)
    |--------------------------------------------------------------------------
    |
    | Cron must process a small batch and exit cleanly.
    */
    'cron_batch_size' => (int) env('POSTIZ_CRON_BATCH_SIZE', 10),

    /*
    |--------------------------------------------------------------------------
    | Retry backoff (minutes)
    |--------------------------------------------------------------------------
    */
    'retry_backoff_minutes' => (int) env('POSTIZ_RETRY_BACKOFF_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Digest interval (minutes)
    |--------------------------------------------------------------------------
    |
    | Minimum age of events before they are eligible for digest.
    */
    'digest_minutes' => (int) env('POSTIZ_DIGEST_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Token refresh window (minutes)
    |--------------------------------------------------------------------------
    |
    | Tokens expiring within this window are considered for refresh.
    */
    'token_refresh_window' => (int) env('POSTIZ_TOKEN_REFRESH_WINDOW', 60),

    /*
    |--------------------------------------------------------------------------
    | Streak reminder gap (hours)
    |--------------------------------------------------------------------------
    */
    'streak_reminder_hours' => (int) env('POSTIZ_STREAK_REMINDER_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Require email verification
    |--------------------------------------------------------------------------
    |
    | When true (e.g. production): register sends activation email, login
    | requires email_verified_at; activate and resend-activation work.
    | When false (e.g. local): no activation email, users can log in immediately.
    |
    */
    'require_email_verification' => filter_var(env('REQUIRE_EMAIL_VERIFICATION', 'false'), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Activation token validity (hours)
    |--------------------------------------------------------------------------
    */
    'activation_token_hours' => (int) env('ACTIVATION_TOKEN_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | CopilotKit – self-hosted runtime (provider, keys, model preference)
    |--------------------------------------------------------------------------
    |
    | Agent chat uses Laravel /copilot/agent (this config). Set COPILOT_PROVIDER
    | (gemini|openai) and the corresponding API key. Also used for captions
    | and image generation.
    |
    | Provider: COPILOT_PROVIDER=gemini|openai. Set GEMINI_API_KEY or OPENAI_API_KEY.
    | Models: OPENAI_MODEL (OpenAI); GEMINI_MODEL + optional POSTIZ_AI_MODEL,
    |   POSTIZ_WRITER_MODEL, POSTIZ_IMAGE_MODEL (Gemini per-task).
    |
    */
    'copilot' => [
        'provider' => env('COPILOT_PROVIDER', 'openai'),
        'openai_api_key' => env('OPENAI_API_KEY', ''),
        'openai_model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'gemini_api_key' => env('GEMINI_API_KEY', ''),
        'gemini_model' => env('GEMINI_MODEL', 'gemini-1.5-flash'),
        'ai_model' => env('POSTIZ_AI_MODEL', env('GEMINI_MODEL', 'gemini-2.5-flash')),
        'writer_model' => env('POSTIZ_WRITER_MODEL', env('GEMINI_MODEL', 'gemini-2.5-flash')),
        'image_model' => env('POSTIZ_IMAGE_MODEL', 'imagen-4.0-generate-001'),
        'runtime_version' => '1.10.6',
        // Rate limit (429) retry: wait and try again. Free tier: e.g. 5 RPM (Pro), 15 RPM (Flash).
        'gemini_retry_max' => (int) env('POSTIZ_GEMINI_RETRY_MAX', 3),
        'gemini_retry_delay_seconds' => (int) env('POSTIZ_GEMINI_RETRY_DELAY', 65),
    ],

    /*
    |--------------------------------------------------------------------------
    | Social integrations OAuth (Add Channel)
    |--------------------------------------------------------------------------
    | FRONTEND_URL: where OAuth redirects (e.g. https://app.example.com).
    | Per-provider: client_id, client_secret; optional scopes.
    | NestJS uses FRONTEND_URL + /integrations/social/{identifier} as redirect_uri.
    */
    'integrations' => [
        'frontend_url' => rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost:4200')), '/'),
        'linkedin' => [
            'client_id' => env('LINKEDIN_CLIENT_ID', ''),
            'client_secret' => env('LINKEDIN_CLIENT_SECRET', ''),
            'scopes' => 'openid profile email w_member_social',
        ],
        'x' => [
            'api_key' => env('X_API_KEY', ''),
            'api_secret' => env('X_API_SECRET', ''),
            'client_id' => env('X_CLIENT_ID', env('X_API_KEY', '')),
            'client_secret' => env('X_CLIENT_SECRET', env('X_API_SECRET', '')),
            'use_oauth2' => filter_var(env('X_USE_OAUTH2', 'true'), FILTER_VALIDATE_BOOLEAN),
        ],
        // Add more providers (facebook, youtube, etc.) as needed via env.
    ],
];

