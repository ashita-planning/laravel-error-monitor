# Installation

Three packages, installed in this order. Each is useful on its own, and each
later one needs the one before it.

```
1. ashita-planning/laravel-error-monitor           analysis, aggregation, contracts
2. ashita-planning/laravel-error-monitor-xserver   XServer stored logs        (optional)
3. ashita-planning/laravel-error-monitor-github    GitHub issue publishing    (optional)
```

Install the core first and get a run producing events before adding anything.
An adapter that is not working is much easier to diagnose when the thing it
plugs into demonstrably is.

## 1. The core

```bash
composer require ashita-planning/laravel-error-monitor
php artisan vendor:publish --provider="Apkk\LaravelErrorMonitor\ErrorMonitorServiceProvider" --tag=error-monitor-config
php artisan migrate
php artisan error-monitor:status
```

`error-monitor:status` tells you whether the tables exist, where it is looking
for logs, and how many it can see. Fix anything it reports before going on.

```bash
php artisan error-monitor:run --dry-run
```

A dry run reads everything and writes nothing. It is the safest way to find out
what a real run would do.

## 2. The XServer adapter

Only on hosting where Apache logs live under the account's home directory. See
[xserver.md](xserver.md).

```bash
composer require ashita-planning/laravel-error-monitor-xserver
php artisan vendor:publish --provider="Apkk\LaravelErrorMonitorXserver\XserverLogSourceServiceProvider" --tag=error-monitor-xserver-config
php artisan error-monitor:xserver-status --date=yesterday
```

## 3. The GitHub adapter

See [github-issues.md](github-issues.md).

```bash
composer require ashita-planning/laravel-error-monitor-github
php artisan vendor:publish --provider="Apkk\LaravelErrorMonitorGithub\GithubErrorMonitorServiceProvider" --tag=error-monitor-github-config
php artisan error-monitor:github-status --check-connection
```

## 4. Schedule it

See [scheduler.md](scheduler.md).

## Verifying the whole chain

```bash
php artisan error-monitor:run --date=yesterday --dry-run --json
```

The JSON reports each source separately. If a source shows `files_analyzed: 0`,
its collector ran and found nothing — check the path. If a source is absent
entirely, its driver is not registered.
