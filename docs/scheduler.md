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

On XServer the logs appear around **06:00**, so a 05:00 run would only ever see
the previous day's files. Either schedule after 07:00, or accept that a 05:00
run sees the day before yesterday. The adapter reads the day's file *and* the
next morning's, so a 07:00 run covers yesterday completely.

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
