<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('ERROR_MONITOR_ENABLED', true),
    'environment' => (string) env('ERROR_MONITOR_ENVIRONMENT', env('APP_ENV', 'production')),
    'timezone' => env('ERROR_MONITOR_TIMEZONE', config('app.timezone', 'UTC')),
    // The path may point at the log directory or at one file inside it; the
    // patterns are always applied to the directory, so the `daily` channel's
    // rotated files are picked up next to the `single` channel's laravel.log.
    'laravel_log_path' => env('ERROR_MONITOR_LARAVEL_LOG_PATH', storage_path('logs/laravel.log')),
    'laravel_log_patterns' => array_filter(explode(',', (string) env('ERROR_MONITOR_LARAVEL_LOG_PATTERNS', 'laravel.log,laravel-*.log'))),

    // Monolog levels treated as failures. Laravel logs client errors at ERROR
    // too, so the HTTP status - not the level - decides what is stored.
    'laravel_log_levels' => array_filter(explode(',', (string) env('ERROR_MONITOR_LARAVEL_LOG_LEVELS', 'ERROR,CRITICAL,ALERT,EMERGENCY'))),

    // Bounds on one run: the newest files only, and never a runaway log.
    'laravel_log_max_files' => (int) env('ERROR_MONITOR_LARAVEL_LOG_MAX_FILES', 31),
    'laravel_log_max_bytes' => (int) env('ERROR_MONITOR_LARAVEL_LOG_MAX_BYTES', 536870912),

    // Apache access logs. The path may name the directory or any file inside
    // it. Rotated and gzip generations are covered by the patterns; compressed
    // files are read as a stream and never expanded onto disk.
    'apache_access_log_path' => env('ERROR_MONITOR_APACHE_ACCESS_LOG_PATH', '/var/log/apache2'),
    'apache_access_log_patterns' => array_filter(explode(',', (string) env(
        'ERROR_MONITOR_APACHE_ACCESS_LOG_PATTERNS',
        'access.log,access_log,access.log.*,access_log.*,*-access.log,*-access.log.*',
    ))),

    // Statuses an access log entry has to carry to become an event. Ranges and
    // single codes are both accepted, e.g. `500-599` or `500,502,503`. This
    // decides what the parser emits; `status_codes` above still decides what is
    // stored, so widen that too to keep 502 and 503.
    'apache_access_status_codes' => array_filter(explode(',', (string) env('ERROR_MONITOR_APACHE_ACCESS_STATUS_CODES', '500-599'))),

    // Extra regexes with named groups, tried before the built-in Common and
    // Combined formats. This is how a custom LogFormat is supported: name the
    // groups `time`, `request`, `status` and optionally `client`, `bytes`,
    // `referer`, `agent`, `request_id`, `request_time`.
    'apache_access_patterns' => [],

    // Matching an Apache 5xx to the Laravel exception that explains it.
    'correlation' => [
        'enabled' => (bool) env('ERROR_MONITOR_CORRELATION_ENABLED', true),

        // How far apart the two entries may be. Deliberately small: the two
        // logs describe the same request, so seconds - not the much wider
        // `analysis.context_*_seconds` used to widen an analysed period.
        'window_seconds' => (int) env('ERROR_MONITOR_CORRELATION_WINDOW_SECONDS', 5),
    ],

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
        // Path fragments deciding which stack frames are "our code" and
        // therefore identify a failure. Fragments rather than absolute paths,
        // so a trace logged on a different deployment root still matches.
        // A vendor fragment always wins over an application one.
        'application_paths' => array_filter(explode(',', (string) env('ERROR_MONITOR_APPLICATION_PATHS', 'app/,routes/,modules/,packages/'))),
        'vendor_paths' => array_filter(explode(',', (string) env('ERROR_MONITOR_VENDOR_PATHS', 'vendor/,node_modules/'))),
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
