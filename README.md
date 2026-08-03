# Laravel Error Monitor

`ashita-planning/laravel-error-monitor` is a Laravel package foundation for detecting, normalizing, and daily-aggregating HTTP 500 errors from application and web-server logs.

## Requirements

- PHP 8.2 or newer
- Laravel 10, 11, or 12

## Installation

```bash
composer require ashita-planning/laravel-error-monitor
php artisan vendor:publish --provider="Apkk\LaravelErrorMonitor\ErrorMonitorServiceProvider" --tag=error-monitor-config
php artisan migrate
```

Laravel discovers the service provider automatically. To verify the installation:

```bash
php artisan error-monitor:status
```

## Configuration

All environment variables use the `ERROR_MONITOR_` prefix. The published `config/error-monitor.php` provides controls for enablement, timezone, Laravel log path, result storage path, masking, fingerprinting, retention, status codes, and a reserved GitHub configuration section.

## Current scope

This initial release provides:

- immutable event, stack-frame, log-file, and analysis-result DTOs;
- contracts for collectors, parsers, normalizers, fingerprints, masking, and persistence;
- conservative masking and normalization of sensitive/dynamic values;
- deterministic SHA-256 fingerprints based on error identity and application stack frames;
- transactional daily aggregation in `error_monitor_events`;
- an issue-link table reserved for a future integration;
- `error-monitor:analyze` and `error-monitor:status` commands.

`error-monitor:analyze` currently invokes the analysis service shell and reports that no collector is configured. It does not read application logs yet.

## Explicitly out of scope

Apache access/error-log parsing, GitHub API calls and issue creation, duplicate issue handling, Codex API calls, and XServer-specific log handling are reserved for a later phase.

## Development

```bash
composer install
composer test
composer format -- --test
composer analyse
```

Fixtures must use only synthetic and reserved documentation values. Never commit production logs, real IP addresses, email addresses, tokens, cookies, or sessions.

## License

MIT. See [LICENSE](LICENSE).
