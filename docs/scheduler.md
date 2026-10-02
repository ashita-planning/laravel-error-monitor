# Scheduling

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('error-monitor:run')
    ->dailyAt('05:00')
    ->onOneServer()
    ->withoutOverlapping();
```

With no options the command analyses **yesterday**, which is the day a morning
run is about.

## Picking the time

Later than the logs are written, earlier than anybody starts work.

On XServer the logs are expected around **06:00**, so schedule after 07:00 and
confirm the expected files are present. A 05:00 run still targets **yesterday**;
it does not automatically switch to the day before yesterday. Without the next
morning's files, yesterday's coverage is incomplete. The adapter reads the day's
file *and* the next morning's to cover the requested day. If generation is late,
re-run that date after the files appear.

For XServer, use `dailyAt('07:00')` instead of the general 05:00 example. Set the
host application's scheduler timezone deliberately; XServer's file timezone is
configured separately by `XSERVER_LOG_TIMEZONE`.

## Starting the Laravel scheduler

The schedule definition alone does not start it. Configure cron for the hosting
account, using the actual application directory and the PHP CLI binary that
matches the application's requirements:

```cron
* * * * * cd /absolute/path/to/application && /absolute/path/to/php artisan schedule:run >> /absolute/path/to/scheduler.log 2>&1
```

Choose a writable log destination, restrict its access and configure rotation.
Check registration with `php artisan schedule:list`. For local development,
`php artisan schedule:work` can keep the scheduler running in a foreground
terminal. Do not run both cron and a second scheduler worker unintentionally.

## `onOneServer()` needs a shared cache

`onOneServer()` and the GitHub adapter's publication lock are both cache locks.
On a per-machine store (`array`, `file`) they cannot coordinate across machines.
Use Redis, Memcached or the database driver if more than one machine runs the
schedule.

Even without one, the command takes its own lock per period and the database
unique constraint remains the real safety net — but two machines could open two
issues for one failure.

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Every source finished |
| `1` | The run failed, or every source failed |
| `2` | Misconfiguration |
| `3` | Another run holds the lock |
| `4` | Collectors ran and matched no log file |
| `5` | Some sources finished and others did not |

`4` after a fresh install usually means a path is wrong. `5` means part of the
run is usable — the sources that succeeded were stored.

Exit code `0` does not prove GitHub publishing succeeded or every expected
XServer file existed. Publication failures are reported in `warnings`, and
missing XServer files are skipped. Monitor JSON `warnings` as well as the exit
code, and check expected files with `error-monitor:xserver-status`.

## Options worth knowing

```bash
php artisan error-monitor:run --dry-run          # nothing stored, published or pruned
php artisan error-monitor:run --skip-github      # analyse and store, publish nothing
php artisan error-monitor:run --date=2026-08-03  # a specific day
php artisan error-monitor:run --source=laravel   # one source
php artisan error-monitor:run --json             # machine readable, per source
```

`--skip-github` is what to use when backfilling: months of history would
otherwise become months of issues.

## Retention

`retention_days` (default 90) removes aggregates older than that at the end of a
successful run. **Pruning is skipped entirely while any source is failing** —
deleting history on the strength of an incomplete analysis would remove exactly
what the failed source might have had something to say about.
