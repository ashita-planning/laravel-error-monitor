# Maintenance

These commands run in the **host Laravel application**, not a package checkout.
Use the deployment procedure, PHP CLI binary and account appropriate to that
application. Package development checks do not validate a production host.

## Changing configuration

1. Update the host's `.env` or published configuration. GitHub publishing reads
   `config/error-monitor-github.php`; the core's `github` section is legacy
   reserved configuration. XServer uses `config/error-monitor-xserver.php`.
2. If the host uses cached configuration, rebuild it:

   ```bash
   php artisan config:cache
   ```

   For an uncached local environment, `php artisan config:clear` removes an old
   cache. Editing `.env` alone does not refresh cached configuration.
3. Check `error-monitor:status`, and the installed adapters' status commands.
   The core status command does not verify the GitHub adapter's configuration
   or the occurrence table.
4. Run `error-monitor:run --date=yesterday --dry-run --json` and inspect its
   window, sources and warnings. A dry run verifies analysis, not publishing.
5. If using GitHub, `error-monitor:github-status --check-connection` verifies
   access by making network requests. Inspect warnings and resulting issues
   after the next intended real run.

Do not republish configuration with `--force` as a routine update: it can
overwrite application-specific settings. Compare published files with the
updated package defaults and add new settings deliberately.

## Updating packages

1. Record the installed versions and preserve the application's
   `composer.json`, `composer.lock`, configuration and database backup according
   to its normal deployment process. Review the core and GitHub CHANGELOGs and
   the XServer CHANGELOG for recorded changes; XServer's new log does not
   reconstruct earlier release history.
2. Resolve and test updates in development or staging. Include only installed
   adapters in the update command. For a host using all three:

   ```bash
   composer update ashita-planning/laravel-error-monitor ashita-planning/laravel-error-monitor-github ashita-planning/laravel-error-monitor-xserver --with-dependencies
   ```

   Review the resulting lockfile and dependency changes before deployment.
   Production normally installs the reviewed lockfile with the application's
   existing deployment procedure rather than resolving new versions there.
3. Compare published configuration with the new package defaults. Run
   `php artisan migrate:status` to identify pending migrations. Check whether
   the host owns published migrations or loads them directly from the package;
   ensure new migrations are available under that arrangement. Apply reviewed
   migrations through the application's deployment process:

   ```bash
   php artisan migrate --force
   ```

   `--force` allows a production migration without an interactive prompt; it
   does not establish that the migration is safe. The core includes a
   timestamp-compatibility migration, so a dependency update alone is not a
   complete update procedure. The GitHub and XServer adapters do not own tables.
4. Rebuild configuration cache if used, check status and run a dry run. Confirm
   all package migrations are applied. The core status command checks only the
   events and issues tables, not `error_monitor_event_occurrences`.
5. Check `php artisan schedule:list`, cron execution and the next intended real
   run. Confirm the expected XServer files, JSON `warnings` and GitHub results.
   Exit code `0` alone does not verify complete file coverage or publication.

## Recovery and stopping a feature

- For a failed publication or late log file, fix the cause and re-run the same
  date. Normal repeated analysis is deduplicated. Do not use `--force` for a
  routine retry: it bypasses occurrence duplicate protection.
- To pause publishing, set `ERROR_MONITOR_GITHUB_ENABLED=false`; for a single
  analysis use `--skip-github`. To pause XServer collection, set
  `ERROR_MONITOR_XSERVER_ENABLED=false`. Rebuild configuration cache if used.
- To pause the entire package, set `ERROR_MONITOR_ENABLED=false` and adjust the
  host schedule deliberately. A scheduled run while disabled exits with code
  `2`; disabling also prevents package migrations from being loaded.
- For a release rollback, restore the reviewed application revision and
  lockfile, then follow its normal `composer install` procedure. Restore cached
  configuration consistently. Database recovery requires a separate plan:
  reverting dependencies does not revert migrations. Do not run a blanket
  `migrate:rollback`, which may include unrelated application changes.
- Removing an adapter does not delete core records or close existing GitHub
  issues. Decide retention and cleanup separately. Disabling the package does
  not erase data already stored or posted.
