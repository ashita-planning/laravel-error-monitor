# Laravel Error Monitor

`ashita-planning/laravel-error-monitor` is a Laravel package foundation for detecting, normalizing, and daily-aggregating HTTP 500 errors from application and web-server logs.

## Requirements

- PHP 8.2 or newer
- Laravel 10, 11, or 12

CI verifies that the package code runs on all three major versions. That is a
statement about code compatibility, not an endorsement of any given framework
release: Laravel 10 and 11 have published security advisories, so the
compatibility matrix installs them with Composer's advisory blocking disabled
for that step only. Choosing a framework version that is safe to run in
production remains the host application's decision. A separate CI job audits the
newest resolvable dependency set under the normal Composer policy.

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

The migrations are loaded from the package. Publish them with
`--tag=error-monitor-migrations` if you prefer to own them in your application.

## Configuration

All environment variables use the `ERROR_MONITOR_` prefix.

| Key | Environment variable | Default | Purpose |
| --- | --- | --- | --- |
| `enabled` | `ERROR_MONITOR_ENABLED` | `true` | Master switch. While disabled, migrations are not loaded and no driver is resolved. |
| `environment` | `ERROR_MONITOR_ENVIRONMENT` | `APP_ENV` | Environment recorded on every event. |
| `timezone` | `ERROR_MONITOR_TIMEZONE` | `app.timezone` | Timezone of the daily bucket. |
| `laravel_log_path` | `ERROR_MONITOR_LARAVEL_LOG_PATH` | `storage/logs/laravel.log` | Where the Laravel logs live. May be the directory or any file inside it; the patterns are applied to the directory either way. |
| `laravel_log_patterns` | `ERROR_MONITOR_LARAVEL_LOG_PATTERNS` | `laravel.log,laravel-*.log` | File patterns of the log source. Covers the `single` and `daily` channels. |
| `laravel_log_levels` | `ERROR_MONITOR_LARAVEL_LOG_LEVELS` | `ERROR,CRITICAL,ALERT,EMERGENCY` | Monolog levels read as failures. The HTTP status, not the level, decides what is stored. |
| `laravel_log_max_files` | `ERROR_MONITOR_LARAVEL_LOG_MAX_FILES` | `31` | Newest files analyzed per run; `0` means no limit. |
| `laravel_log_max_bytes` | `ERROR_MONITOR_LARAVEL_LOG_MAX_BYTES` | `536870912` | Larger files are skipped; `0` means no limit. |
| `apache_access_log_path` | `ERROR_MONITOR_APACHE_ACCESS_LOG_PATH` | `/var/log/apache2` | Where the Apache access logs live. Directory or any file inside it. |
| `apache_access_log_patterns` | `ERROR_MONITOR_APACHE_ACCESS_LOG_PATTERNS` | `access.log,access_log,access.log.*,…` | File patterns, including the rotated and `.gz` generations. |
| `apache_access_status_codes` | `ERROR_MONITOR_APACHE_ACCESS_STATUS_CODES` | `500-599` | Which statuses become events. Ranges and single codes, e.g. `500-599` or `500,502,503`. |
| `apache_access_patterns` | – | `[]` | Extra regexes with named groups for a custom `LogFormat`, tried before the built-in formats. |
| `apache_error_log_path` | `ERROR_MONITOR_APACHE_ERROR_LOG_PATH` | `/var/log/apache2` | Where the Apache error logs live. Directory or any file inside it. |
| `apache_error_log_patterns` | `ERROR_MONITOR_APACHE_ERROR_LOG_PATTERNS` | `error.log,error_log,error.log.*,…` | File patterns, including the rotated and `.gz` generations. |
| `apache_error_log_levels` | `ERROR_MONITOR_APACHE_ERROR_LOG_LEVELS` | `error,crit,alert,emerg,warn` | Apache levels read as failures. `warn` is included because mod_fcgid reports a read timeout at that level. |
| `correlation.enabled` | `ERROR_MONITOR_CORRELATION_ENABLED` | `true` | Whether Apache 5xx are matched to Laravel exceptions. |
| `correlation.window_seconds` | `ERROR_MONITOR_CORRELATION_WINDOW_SECONDS` | `5` | How far apart the two entries may be. Both logs describe the same request, so this is seconds — not the much wider `analysis.context_*_seconds`. |
| `results_path` | `ERROR_MONITOR_RESULTS_PATH` | `storage/app/error-monitor` | Where collected material may be kept. |
| `retention_days` | `ERROR_MONITOR_RETENTION_DAYS` | `90` | How long aggregates are kept. |
| `status_codes` | `ERROR_MONITOR_STATUS_CODES` | `500` | Statuses worth storing. |
| `analysis.context_before_seconds` / `context_after_seconds` | `ERROR_MONITOR_CONTEXT_*_SECONDS` | `1800` | Widen the analyzed period for correlation. |
| `analysis.lock_seconds` | `ERROR_MONITOR_LOCK_SECONDS` | `900` | Lifetime of the run lock. |
| `masking.*` | `ERROR_MONITOR_MASKING_*` | enabled | Replacement tokens, masked keys, removed headers, query string removal, extra patterns, length bound. |
| `fingerprint.application_paths` / `vendor_paths` | `ERROR_MONITOR_APPLICATION_PATHS` / `ERROR_MONITOR_VENDOR_PATHS` | `app/,routes/,modules/,packages/` / `vendor/,node_modules/` | Path fragments deciding which stack frames are yours. A vendor fragment always wins. |
| `fingerprint.*` | `ERROR_MONITOR_FINGERPRINT_*` | all included | Stack frame limit, and whether the line number, HTTP method and route take part in the identity. |
| `github.*` | `ERROR_MONITOR_GITHUB_*` | disabled | Reserved for the future issue integration. Never read by any HTTP call today. |

Keep defaults in the config file - the code never hardcodes them.

## Commands

The daily command reads every configured source in one go:

```bash
php artisan error-monitor:run
php artisan error-monitor:run --date=2026-08-03
php artisan error-monitor:run --source=laravel --dry-run --json
php artisan error-monitor:run --skip-github
```

With no options it analyses **yesterday**, which is the day a morning run is
about. It resolves the period, reads Laravel then Apache access then Apache
error, correlates them, stores idempotently, hands the failures to an issue
publisher if one is installed, and finally drops aggregates past
`retention_days`.

Exit codes of `error-monitor:run`:

| Code | Meaning |
| --- | --- |
| `0` | Every source finished |
| `1` | The run failed outright, or every source failed |
| `2` | Misconfiguration: disabled package, unusable date, contradictory options |
| `3` | Another run holds the lock for the same period |
| `4` | Collectors ran but matched no log file |
| `5` | Some sources finished and others did not |

Sources fail independently: an unreadable Apache directory says nothing about
the Laravel log beside it, so one failing source does not discard the others'
results, and `5` is how a partial run reports itself. Retention pruning is
skipped entirely while any source is failing.

`--dry-run` writes nothing at all — no database, no publisher, no pruning — but
still reports what a real run would have stored. `--skip-github` only suppresses
the `IssuePublisher` call; this package contains no issue-tracker code.

### Scheduling

```php
// routes/console.php, or the schedule() method of your console kernel
use Illuminate\Support\Facades\Schedule;

Schedule::command('error-monitor:run')
    ->dailyAt('05:00')
    ->onOneServer()
    ->withoutOverlapping();
```

`onOneServer()` needs a shared cache store. Even without it the command takes
its own cache lock per period, and the database unique constraint remains the
real safety net.

`error-monitor:analyze` stays available for analysing an arbitrary period:

```bash
php artisan error-monitor:analyze
php artisan error-monitor:analyze --date=yesterday
php artisan error-monitor:analyze --from="2026-08-03 00:00:00" --to="2026-08-03 12:00:00"
php artisan error-monitor:analyze --source=laravel --dry-run --json
php artisan error-monitor:analyze --force

php artisan error-monitor:status
php artisan error-monitor:status --json
```

Exit codes of `error-monitor:analyze`:

| Code | Meaning |
| --- | --- |
| `0` | Analysis completed |
| `1` | Analysis failed |
| `2` | Misconfiguration: disabled package, unusable date, contradictory options |
| `3` | Another run holds the lock for the same period |
| `4` | Collectors ran but matched no log file |

Concurrent runs of the same period are prevented with a cache lock; the database
unique constraint remains the real safety net. `error-monitor:status` prints no
secrets - the GitHub token is only reported as configured or not.

## Masking and normalization

Masking runs before anything is normalized, fingerprinted or persisted, and the
original values are never returned or stored.

| Masked | Replacement |
| --- | --- |
| IPv4 / IPv6 | `{ip}` |
| E-mail addresses | `{email}` |
| Phone numbers (separated, `+`-prefixed, or a bare 10-11 digits behind a leading zero) | `{phone}` |
| UUIDs | `{uuid}` |
| Bearer tokens, JWTs, Authorization headers, CSRF tokens | `{token}` |
| Cookie / Set-Cookie headers, session identifiers | `{session}` |
| Passwords, API keys, client secrets, refresh tokens, provider key formats | `{secret}` |
| Query strings | removed |

Arrays are masked recursively, and any key listed in `masking.mask_keys` (or in
`masking.remove_headers`) has its whole value replaced, whatever it contains.
A key listed in `masking.phone_keys` is replaced with `{phone}` instead - free
text cannot tell an unseparated number from an amount, so the key settles it.

A number is only read as a phone number when it looks like one: it carries
separators, starts with `+`, sits behind a `TEL:` / `phone=` style label, or is
ten to eleven digits behind a leading zero. Bare integers are left alone, which
is what keeps amounts, quantities, line numbers, ids and path segments intact -
masking runs first, so anything it removes cannot be recovered later.
Two limits are worth knowing: the masker is pattern based, so unknown secret
formats need an entry in `masking.patterns`, and values longer than
`masking.max_length` are truncated before masking. If a rule cannot run at all,
the value is redacted instead of passed through.

Normalization replaces values that differ between two occurrences of the same
failure - ids, timestamps, query strings, temporary paths, framework generated
files, digests and long random values - and deliberately keeps the values that
identify a failure: HTTP statuses, PHP error constants, SQLSTATE and driver error
codes, line numbers, version numbers, amounts and quantities.

## Apache access logs

The access log sees what the application log cannot: a 502 or a 503 never
reaches PHP and therefore leaves no Laravel entry at all. Common and Combined
Log Format are read out of the box, rotated `.gz` generations are streamed
without ever being expanded onto disk, and a custom `LogFormat` is supported by
adding a regex with named groups to `apache_access_patterns` — name them `time`,
`request` and `status`, optionally `client`, `bytes`, `referer`, `agent`,
`request_id` and `request_time`.

The status here is reported by the server, never inferred, so events carry
`metadata.status_source = access_log` and `status_estimated = false`.

Query strings are cut from the path in the parser itself rather than left to the
masker, because a token in a URL is routine in an access log. The client address
goes through the normal masking, so no raw IP is stored.

### Correlating with Laravel

Each 5xx is matched against the Laravel exceptions of the same moment, strongest
signal first. The result is recorded, because a match is a judgement rather than
a fact:

| `correlation_method` | Matched on | `correlation_confidence` |
| --- | --- | --- |
| `request_id` | A request id present on both sides | `1.0` |
| `time_method_path` | Same moment, HTTP method and normalized path | `0.8` |
| `time_path` | Same moment and normalized path | `0.6` |
| `time` | Proximity in time alone | `0.3` |
| `none` | Nothing matched | `0.0` |

Paths are compared after normalization, so `/orders/12` and `/orders/99` are the
same route. When several candidates are equally plausible the nearest in time is
chosen and the confidence is divided by the number of candidates, with
`correlation_candidates` recording how many there were.

**A 5xx without a Laravel counterpart is never dropped.** It is stored as its own
event with `correlation_method: none` — those are precisely the failures that
never reached the application.

Note that `status_codes` still decides what is persisted. It defaults to `500`,
so set `ERROR_MONITOR_STATUS_CODES=500,502,503,504` to keep gateway errors.

## Apache error logs

The error log holds the failures that never reached PHP at all: a process killed
for exhausting memory, a request that outlived its timeout, a FastCGI transport
that gave up, a permission the deploy got wrong. A PHP stack trace spanning
several lines becomes one event, and rotated `.gz` generations are streamed like
the access log.

Each entry is sorted into the kind of failure it describes, recorded in
`metadata.error_category`:

| Category | Recognised from |
| --- | --- |
| `memory_exhausted` | `Allowed memory size … exhausted`, out of memory |
| `timeout` | `Maximum execution time … exceeded`, `read data timeout`, `AH01075` |
| `php_fatal` | `PHP Fatal error`, `Uncaught …`, `PHP Startup`, parse errors |
| `permission` | `Permission denied`, `client denied by server configuration`, `AH01797` |
| `fastcgi` | `FastCGI sent in stderr`, `proxy_fcgi`, `Premature end of script headers`, `AH01071` |
| `configuration` | `.htaccess`, `Invalid command`, `AH00124` |
| `missing_file` | `File does not exist`, `script not found` |
| `server_internal` | `child pid … exit signal`, `Segmentation fault`, `AH00052` |
| `unknown` | nothing matched — `category_estimated` is then `true` |

Rules run from specific to general, because the specific ones are the actionable
ones: an exhausted memory limit is also a PHP fatal error, and an `AH01071`
quoting a PHP fatal is a bug in the application rather than in the transport
that reported it.

An error log states no HTTP status, so it is derived from the category and every
event says so through `status_source = error_category` and
`status_estimated = true`. `missing_file` maps to 404 and `permission` to 403,
which is what keeps a scanner sweep out of the stored server errors: they are
parsed and then simply not stored under the default `status_codes`.

Entries can be annotated with a matching Laravel exception through the same
`ApacheLaravelCorrelationService`. An error log entry usually carries no request
path, so proximity in time is often all there is — which the recorded confidence
states rather than hides.

## Fingerprints

SHA-256 over environment, source, exception class, normalized message, the first
application file and line, the leading application stack frames, the HTTP method
and the normalized route. Vendor frames are used only when a trace has no
application frame. `FingerprintGenerator::material()` returns the same input for
inspection, and `config('error-monitor.fingerprint')` decides which parts count.

## Tables

| Table | Purpose |
| --- | --- |
| `error_monitor_events` | Daily aggregate per failure. Unique on `(environment, source, fingerprint, detected_date)`. Its `payload_hash` names the payload processed **last** and is kept for reference only; it is not what duplicate protection reads. |
| `error_monitor_event_occurrences` | One row per distinct payload merged into a daily aggregate, unique on `(error_monitor_event_id, payload_hash)`. This is what makes re-analyzing a log a no-op: a day holding several distinct entries for one fingerprint remembers all of them, not just the newest. |
| `error_monitor_issues` | Fingerprint to issue correspondence, unique on `(environment, fingerprint, repository)`. Reserved for the future issue integration; nothing in this package writes to it yet. |

## Extending

Every step sits behind a contract, so rebinding one is enough to replace it.
Log drivers are registered through container tags - the bundled Laravel driver
registers itself the same way, and additional formats are purely additive:

```php
use Apkk\LaravelErrorMonitor\ErrorMonitorServiceProvider;

$this->app->tag([ApacheAccessLogCollector::class], ErrorMonitorServiceProvider::COLLECTOR_TAG);
$this->app->tag([ApacheAccessLogParser::class], ErrorMonitorServiceProvider::PARSER_TAG);
```

A collector tags every file it finds with its own source key, and the analyzer
hands each file to the first parser whose `supports()` claims it, so parsers for
different formats never collide.

## Current scope

Implemented:

- immutable event, stack-frame, log-file, analysis-result and analysis-window DTOs;
- contracts for collectors, parsers, normalizers, fingerprints, masking, persistence and issue publishing;
- the Laravel log driver: file discovery for the `single` and `daily` channels, and a streaming parser for the Monolog default format including multi-line stack traces and the JSON context;
- the Apache access log driver: Common and Combined Log Format, rotated and gzip generations, configurable status range and custom `LogFormat` patterns, plus correlation with Laravel exceptions and a recorded confidence;
- the Apache error log driver: multi-line PHP stack traces, gzip generations, and classification into nine kinds of server failure with the status derived from the category;
- masking of personal data and credentials, including arrays and sensitive keys;
- conservative normalization of dynamic values;
- deterministic SHA-256 fingerprints with configurable materials;
- transactional daily aggregation in `error_monitor_events` and the issue link repository;
- `error-monitor:analyze` and `error-monitor:status` with the options and exit codes above.

On a stock installation `error-monitor:analyze` reads `storage/logs`, keeps the
entries whose HTTP status matches `status_codes`, and aggregates them per day.

Laravel logs client errors at `ERROR` level too, so the status is derived from
the log context first, then from the exception class, and only assumed to be
`500` as a last resort. Every event records which of the three applied in
`metadata.status_source`, alongside `metadata.status_estimated`, so an assumed
status is never mistaken for a reported one.

## Explicitly out of scope

GitHub API calls and issue creation, duplicate issue handling, AI agent API
calls, and XServer-specific log handling are reserved for a later phase, and are
to live in their own packages rather than in this one.

## Development

```bash
composer update       # no composer.lock is committed; a library resolves fresh
composer test         # PHPUnit through Orchestra Testbench
composer format       # Pint
composer format:test  # Pint, check only
composer analyse      # PHPStan
composer check        # all three
```

CI runs the same gates on PHP 8.2/8.3/8.4 against Laravel 10/11/12, plus the
migrations and both Artisan commands inside a Testbench application, and audits
the current dependency set in a separate job.

Fixtures must use only synthetic and reserved documentation values. Never commit production logs, real IP addresses, email addresses, tokens, cookies, or sessions.

## License

MIT. See [LICENSE](LICENSE).
