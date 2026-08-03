<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('ERROR_MONITOR_ENABLED', true),
    'timezone' => env('ERROR_MONITOR_TIMEZONE', config('app.timezone', 'UTC')),
    'laravel_log_path' => env('ERROR_MONITOR_LARAVEL_LOG_PATH', storage_path('logs/laravel.log')),
    'results_path' => env('ERROR_MONITOR_RESULTS_PATH', storage_path('app/error-monitor')),
    'retention_days' => (int) env('ERROR_MONITOR_RETENTION_DAYS', 90),
    'status_codes' => array_map('intval', explode(',', (string) env('ERROR_MONITOR_STATUS_CODES', '500'))),

    'masking' => [
        'enabled' => (bool) env('ERROR_MONITOR_MASKING_ENABLED', true),
        'replacement_tokens' => [
            'ip' => '{ip}',
            'email' => '{email}',
            'phone' => '{phone}',
            'uuid' => '{uuid}',
            'token' => '{token}',
            'session' => '{session}',
        ],
    ],

    'fingerprint' => [
        'application_paths' => array_filter(explode(',', (string) env('ERROR_MONITOR_APPLICATION_PATHS', base_path('app')))),
        'stack_frame_limit' => (int) env('ERROR_MONITOR_STACK_FRAME_LIMIT', 3),
    ],

    // Reserved configuration only. GitHub API integration is intentionally not implemented yet.
    'github' => [
        'enabled' => (bool) env('ERROR_MONITOR_GITHUB_ENABLED', false),
        'repository' => env('ERROR_MONITOR_GITHUB_REPOSITORY'),
        'labels' => array_filter(explode(',', (string) env('ERROR_MONITOR_GITHUB_LABELS', 'bug,error-monitor'))),
    ],
];
