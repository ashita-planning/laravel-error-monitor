# Installation

Install the core first, then choose either or both optional adapters. Both
adapters depend on the core; neither depends on the other. The core can run
without either adapter.

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

The JSON reports sources that supplied files separately. An absent source may
be disabled, unregistered, or simply have no matching readable files; absence
alone does not distinguish these cases. Compare `sources_configured` with the
status commands and check the paths. For XServer, use
`error-monitor:xserver-status --date=yesterday` to inspect missing files.

A dry run checks collection and analysis, but does not verify GitHub publishing.
Use `error-monitor:github-status --check-connection` to check access, then inspect
the first approved real run's `warnings` and resulting issues.

After changing configuration on a host that uses a configuration cache, rebuild
it with `php artisan config:cache`. See [maintenance.md](maintenance.md) for
updates and configuration changes.
