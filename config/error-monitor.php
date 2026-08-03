<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('ERROR_MONITOR_ENABLED', true),
    'environment' => (string) env('ERROR_MONITOR_ENVIRONMENT', env('APP_ENV', 'production')),
    'timezone' => env('ERROR_MONITOR_TIMEZONE', config('app.timezone', 'UTC')),
    'laravel_log_path' => env('ERROR_MONITOR_LARAVEL_LOG_PATH', storage_path('logs/laravel.log')),
    'laravel_log_patterns' => array_filter(explode(',', (string) env('ERROR_MONITOR_LARAVEL_LOG_PATTERNS', 'laravel.log,laravel-*.log'))),
    'results_path' => env('ERROR_MONITOR_RESULTS_PATH', storage_path('app/error-monitor')),
    'retention_days' => (int) env('ERROR_MONITOR_RETENTION_DAYS', 90),
    'status_codes' => array_map('intval', explode(',', (string) env('ERROR_MONITOR_STATUS_CODES', '500'))),

    // Bounds of an analysis run. The context seconds widen the requested period
    // so an entry written just outside a day boundary can still be correlated
    // with the request that produced it. `lock_seconds` bounds the run lock the
    // analyze command takes to keep two runs off the same period.
    'analysis' => [
        'context_before_seconds' => (int) env('ERROR_MONITOR_CONTEXT_BEFORE_SECONDS', 1800),
        'context_after_seconds' => (int) env('ERROR_MONITOR_CONTEXT_AFTER_SECONDS', 1800),
        'lock_seconds' => (int) env('ERROR_MONITOR_LOCK_SECONDS', 900),
    ],

    'masking' => [
        'enabled' => (bool) env('ERROR_MONITOR_MASKING_ENABLED', true),
        'replacement_tokens' => [
            'ip' => '{ip}',
            'email' => '{email}',
            'phone' => '{phone}',
            'uuid' => '{uuid}',
            'token' => '{token}',
            'session' => '{session}',
            'secret' => '{secret}',
        ],

        // Query strings routinely carry tokens and mail addresses.
        'remove_query_strings' => (bool) env('ERROR_MONITOR_REMOVE_QUERY_STRINGS', true),

        // Headers whose value is always replaced, whatever it contains.
        'remove_headers' => [
            'authorization',
            'cookie',
            'set-cookie',
            'x-csrf-token',
        ],

        // Array keys whose value is always replaced. Compared case
        // insensitively and ignoring `-`, `_` and `.` separators.
        'mask_keys' => [
            'password',
            'password_confirmation',
            'secret',
            'token',
            'access_token',
            'refresh_token',
            'authorization',
            'cookie',
            'session',
            'session_id',
            'api_key',
            'client_secret',
        ],

        // Application specific rules, applied after the built-in ones.
        'patterns' => [],

        // Longer values are truncated before masking, so a runaway log line
        // cannot turn into a regular expression denial of service.
        'max_length' => (int) env('ERROR_MONITOR_MASKING_MAX_LENGTH', 262144),
    ],

    'fingerprint' => [
        'application_paths' => array_filter(explode(',', (string) env('ERROR_MONITOR_APPLICATION_PATHS', base_path('app')))),
        'stack_frame_limit' => (int) env('ERROR_MONITOR_STACK_FRAME_LIMIT', 3),

        // Materials that can be excluded from the identity of a failure.
        'include_line_number' => (bool) env('ERROR_MONITOR_FINGERPRINT_LINE_NUMBER', true),
        'include_method' => (bool) env('ERROR_MONITOR_FINGERPRINT_METHOD', true),
        'include_route' => (bool) env('ERROR_MONITOR_FINGERPRINT_ROUTE', true),
    ],

    // Reserved configuration only. GitHub API integration is intentionally not implemented yet.
    'github' => [
        'enabled' => (bool) env('ERROR_MONITOR_GITHUB_ENABLED', false),
        'repository' => env('ERROR_MONITOR_GITHUB_REPOSITORY'),
        'token' => env('ERROR_MONITOR_GITHUB_TOKEN'),
        'labels' => array_filter(explode(',', (string) env('ERROR_MONITOR_GITHUB_LABELS', 'bug,error-monitor'))),
    ],
];
