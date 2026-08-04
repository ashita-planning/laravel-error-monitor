# XServer logs

XServer stores Apache logs under the hosting account itself:

```
/home/{server_id}/{domain}/log/{domain}.access_log_YYYYMMDD.gz
/home/{server_id}/{domain}/log/{domain}.error_log_YYYYMMDD.gz
```

Turn log storage on in the XServer control panel first; the files do not exist
until you do.

```dotenv
ERROR_MONITOR_XSERVER_ENABLED=true
XSERVER_SERVER_ID=sv00000
XSERVER_DOMAIN=shop.example.com
```

## The detail that catches everybody

**A file is named after the morning it was written, not the day it describes.**

| File | Actually covers |
| --- | --- |
| `…access_log_20260804.gz` | 2026-08-03 **04:00** → 2026-08-04 **04:00** |
| `…error_log_20260804.gz` | 2026-08-03 **03:00** → 2026-08-04 **03:00** |

So the file dated the 4th is mostly about the 3rd, and the two kinds do not even
share a boundary. Investigating one day means reading **two files per kind** —
the day's own and the next morning's. The adapter does this for you; the reason
it matters is that reading only `…_20260803.gz` to investigate the 3rd would
silently miss everything after 04:00, which is most of the day.

Each file reports its real bounds in `metadata.coverage_start_local` and
`coverage_end_local`, so nothing downstream has to infer it.

## Missing files are normal

- Today's log does not exist until tomorrow morning (~06:00).
- XServer does not write the error log at all while the account is above 80%
  disk usage.

Both are reported by `error-monitor:xserver-status` and skipped, never treated
as failures.

```bash
php artisan error-monitor:xserver-status --date=yesterday
```

Run this before blaming the configuration: it separates "the path is wrong" from
"the log has not been written yet", which look identical from the outside.

## Format

XServer prefixes every access log line with the virtual host:

```
shop.example.com 192.0.2.0 - - [11/Jul/2013:12:09:17 +0900] "GET / HTTP/1.0" 200 2602 "-" "Mozilla/5.0"
```

The adapter teaches the core's existing Apache parser this shape by adding a
pattern to `error-monitor.apache_access_patterns`. No second parser exists.

## What it does not do

Files are handed over **still compressed** — the core streams `.gz` itself.
Original files are never modified or deleted. This version reads what is already
on disk; API retrieval, SSH/SFTP and multi-server collection are not
implemented, and no credentials are involved.
