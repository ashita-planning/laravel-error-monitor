# Troubleshooting

Start here:

```bash
php artisan error-monitor:status
php artisan error-monitor:xserver-status --date=yesterday
php artisan error-monitor:github-status
php artisan error-monitor:run --date=yesterday --dry-run --json
```

The JSON reports every source separately, which is usually enough to tell which
half of the chain is at fault.

| Symptom | Likely cause |
| --- | --- |
| Exit code `4`, nothing stored | The collectors ran and matched no file. Check `laravel_log_path` and the XServer path. |
| Exit code `5` | One source failed; the others were stored. The JSON names it. |
| `No log collector is configured yet` | The package is disabled, or no driver is registered. |
| Events stored but no issues | The GitHub adapter is off, no token, or `--skip-github`. |
| Everything skipped, nothing stored | `status_codes` excludes them. Apache 403/404 are excluded on purpose. |
| A 500 with no correlation | The Laravel and Apache timestamps disagree. See below. |
| `Several GitHub issues carry this fingerprint` | Two issues have the same marker. Close or edit one; this is deliberately not resolved automatically. |
| Issues appear twice | The cache store cannot lock across machines. Use Redis, Memcached or the database driver. |
| `GitHub answered 404` | The repository does not exist, or the token cannot see it. |
| `GitHub refused the request` (403) | The token lacks **Issues: read and write**. |
| Rate limit failure | A `Retry-After` longer than `retry.max_wait_ms`. Raise it, or let the next run handle it. |
| XServer file missing | Normal. Today's log is written next morning, and the error log is not written above 80% disk. |

## Correlation is not happening

Two causes, in order of likelihood.

**The clocks disagree.** Apache writes an explicit offset
(`[03/Aug/2026:11:19:29 +0900]`); Laravel usually writes none, so it is read in
`error-monitor.timezone`. If that is `UTC` while the server writes Japan time,
every pair is nine hours apart and nothing correlates. Set
`ERROR_MONITOR_TIMEZONE` to the server's actual timezone.

**The Laravel context could not be decoded.** Method and route come from the
JSON context on the log line. Laravel's default formatter writes exception
traces with real line breaks inside that JSON, which makes it undecodable — so
`route` is null and the best available match drops to proximity in time
(`correlation_method: time`, confidence 0.3) instead of
`time_method_path` (0.8). It still correlates; it is just less certain.

## Reading `metadata`

| Key | Says |
| --- | --- |
| `status_source` | `context`, `exception_class`, `access_log`, `error_category` or `assumed` |
| `status_estimated` | Whether the HTTP status was reported or inferred |
| `correlation_method` / `_confidence` | How an Apache entry was tied to an exception |
| `correlation_candidates` | How many were plausible; more than one divides the confidence |
| `error_category` | What kind of server failure it was |

`status_estimated: true` means nobody told us it was a 500 — a Laravel
exception with no mapped status is assumed to be one. Worth knowing before
acting on it.

## Re-running safely

Re-running any day is safe. Occurrence history prevents double counting, and the
core will not offer a report it has already published. `--force` deliberately
bypasses the first of those; it does not bypass the second.
