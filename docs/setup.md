# Guided setup

Run these commands in the host Laravel application after installing the packages.
They prepare files only: no dependency installation, migration, cache rebuild,
schedule modification, network request or log analysis is performed by setup.

```bash
php artisan error-monitor:setup --dry-run --json
php artisan error-monitor:setup
php artisan error-monitor:doctor --json
```

Core setup publishes `config/error-monitor.php` only if absent and appends
missing `ERROR_MONITOR_ENABLED` and `ERROR_MONITOR_TIMEZONE` settings to the
application's environment file. Timezone comes from the host's `app.timezone`;
verify it against the actual logs. The existing default Laravel log patterns
cover single/daily channels; custom logging still needs configuration.

With the GitHub adapter installed:

```bash
php artisan error-monitor:github-setup --repository=acme/shop --enable --dry-run
php artisan error-monitor:github-setup --repository=acme/shop --enable
```

This publishes missing adapter config and adds missing destination/enabled keys.
Without `--enable`, a missing enabled key is set to false. Existing keys, even
empty values or `false`, are preserved. To change them, edit only the intended
host settings. Tokens are never accepted as CLI arguments or output by setup;
provision `ERROR_MONITOR_GITHUB_TOKEN` through the host secret mechanism.

With the XServer adapter installed:

```bash
php artisan error-monitor:xserver-setup --server-id=sv00000 --domain=example.invalid --enable --dry-run
php artisan error-monitor:xserver-setup --server-id=sv00000 --domain=example.invalid --enable
```

Replace the synthetic examples with the intended account/domain. Multiple
domains are comma-separated. Enable hosting log storage separately.

## Preservation and results

All setup commands support `--dry-run` and `--json`; results are JSON even
without `--json`, for consistent Agent consumption. They report key names, not
environment values. Existing config, comments and assignments are preserved;
new environment files use mode `0600`. Symlink targets are rejected. No backup
of secrets is created. Each file operation is independent: if an environment
append fails after config publication, the config may already exist. Correct
the permissions and re-run; setup preserves it and retries missing keys.

Success means file preparation, not an active installation. Existing process
configuration is not reloaded. Rebuild cached configuration if used, review
and apply migrations through the host deployment process, and configure its
scheduler. See [maintenance.md](maintenance.md) and [scheduler.md](scheduler.md).

`doctor` returns code `0` when core monitoring is enabled, the timezone is valid
and all three core tables exist; otherwise `1`. It reports readable Laravel log
file count separately since no logs can be normal. It does not inspect pending
migrations, external adapters, expected XServer coverage or cron execution.
No database error message, credential or raw log is printed.

Use `migrate:status`, adapter status commands, analysis dry-run and the first
intended real run to complete verification. GitHub publication warnings may
accompany exit code `0` from `error-monitor:run`.

## AI Agent setup

The core package includes the personal
[apkk-laravel-error-monitor-setup skill](../skills/apkk-laravel-error-monitor-setup/SKILL.md).
Copy its directory to the Agent's personal skill directory, then ask it to configure the intended Laravel project
and Issue destination. The skill orchestrates host setup outside the package;
package code calls no AI API. The skill and commands do not authorize production
migrations, deployment or actual Issue creation implicitly.
