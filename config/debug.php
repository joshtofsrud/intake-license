<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | When false, all calls to the DebugLogService return null without
    | writing. Useful for tests and for emergency disable without a deploy.
    */
    'enabled' => env('DEBUG_LOG_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Per-channel enable toggles
    |--------------------------------------------------------------------------
    | Each channel can be turned off independently. Keep errors and audit
    | on in production; 'request' and 'debug' are the heaviest.
    */
    'channels' => [
        'request'       => env('DEBUG_LOG_REQUESTS',       true),
        'error'         => env('DEBUG_LOG_ERRORS',         true),
        'job'           => env('DEBUG_LOG_JOBS',           true),
        'mail'          => env('DEBUG_LOG_MAIL',           true),
        'sms'           => env('DEBUG_LOG_SMS',            true),
        'auth'          => env('DEBUG_LOG_AUTH',           true),
        'impersonation' => env('DEBUG_LOG_IMPERSONATION',  true),
        'audit'         => env('DEBUG_LOG_AUDIT',          true),
        'webhook'       => env('DEBUG_LOG_WEBHOOKS',       true),
        'api'           => env('DEBUG_LOG_API',            true),
        'system'        => env('DEBUG_LOG_SYSTEM',         true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request logging
    |--------------------------------------------------------------------------
    | Sampling rate lets us keep logging lightweight on high-traffic routes.
    | 1.0 = log everything, 0.1 = log 10% of successful requests.
    | Errors and slow requests are always logged regardless of sampling.
    */
    'request' => [
        // Routes we never log (health checks, assets, etc). Matched against
        // path, so patterns like 'health' will match '/health' and '/api/health'.
        'skip_paths' => [
            'up', 'health', 'favicon.ico',
            'build/', 'assets/', 'storage/', 'css/', 'js/', 'images/',
        ],

        // Log every request below this threshold at this sample rate.
        // Requests slower than slow_threshold_ms are always logged.
        'sample_rate'        => env('DEBUG_LOG_REQUEST_SAMPLE', 1.0),
        'slow_threshold_ms'  => env('DEBUG_LOG_SLOW_MS', 1500),

        // Never capture these query string / form fields in request context.
        'redact_keys' => [
            'password', 'password_confirmation', 'current_password',
            'token', '_token', 'api_key', 'apikey',
            'secret', 'client_secret',
            'card', 'card_number', 'cvc', 'cvv',
            'ssn', 'tax_id',
            'twilio_auth_token', 'stripe_secret',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | How long to keep logs by channel (in days). Run:
    |   php artisan debug-log:prune
    | …on a schedule (see routes/console.php).
    */
    'retention_days' => [
        'request'       => env('DEBUG_LOG_RETENTION_REQUEST',        14),
        'error'         => env('DEBUG_LOG_RETENTION_ERROR',          90),
        'job'           => env('DEBUG_LOG_RETENTION_JOB',            30),
        'mail'          => env('DEBUG_LOG_RETENTION_MAIL',           90),
        'sms'           => env('DEBUG_LOG_RETENTION_SMS',            90),
        'auth'          => env('DEBUG_LOG_RETENTION_AUTH',           90),
        'impersonation' => env('DEBUG_LOG_RETENTION_IMPERSONATION', 365),
        'audit'         => env('DEBUG_LOG_RETENTION_AUDIT',         365),
        'webhook'       => env('DEBUG_LOG_RETENTION_WEBHOOK',        30),
        'api'           => env('DEBUG_LOG_RETENTION_API',            30),
        'system'        => env('DEBUG_LOG_RETENTION_SYSTEM',         30),

        // Resolved errors get pruned sooner than open ones.
        'resolved_error' => env('DEBUG_LOG_RETENTION_RESOLVED', 30),
    ],

];
